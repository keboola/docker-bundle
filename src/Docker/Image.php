<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Exception;
use InvalidArgumentException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Psr\Log\LoggerInterface;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Process\Process;
use Throwable;

abstract class Image
{
    private const MAX_CONFIG_TIMEOUT = 24 * 60 * 60; // 24 hours

    /**
     * @var string
     */
    protected $imageId;

    /**
     * @var string
     */
    protected $tag = 'latest';

    /**
     * @var string
     */
    protected $digest;

    /**
     * @var array
     */
    protected $configData;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var bool
     */
    private $isMain;

    private ComponentSpecification $component;

    /**
     * @var array
     */
    private $imageDigests;

    /**
     * @var int
     */
    protected $retryMinInterval = 500;

    /**
     * @var int
     */
    protected $retryMaxInterval = 60000;

    /**
     * @var int
     */
    protected $retryMaxAttempts = 20;

    abstract protected function pullImage();

    public function __construct(ComponentSpecification $component, LoggerInterface $logger)
    {
        $this->component = $component;
        $this->logger = $logger;
        $this->imageId = $component->getImageDefinition()['uri'];
        if (!empty($component->getImageDefinition()['tag'])) {
            $this->tag = $component->getImageDefinition()['tag'];
        }
        $this->digest = $component->getImageDefinition()['digest'];
    }

    /**
     * @param int $minInterval
     * @param int $maxInterval
     * @param int $maxAttempts
     */
    public function setRetryLimits($minInterval, $maxInterval, $maxAttempts)
    {
        $this->retryMinInterval = $minInterval;
        $this->retryMaxInterval = $maxInterval;
        $this->retryMaxAttempts = $maxAttempts;
    }

    /**
     * @return string
     */
    public function getImageId()
    {
        return $this->imageId;
    }

    /**
     * @return string
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * @param bool $isMain
     */
    public function setIsMain($isMain)
    {
        $this->isMain = $isMain;
    }

    /**
     * @return bool
     */
    public function isMain()
    {
        return $this->isMain;
    }

    /**
     * @return array
     */
    public function getConfigData()
    {
        return $this->configData;
    }

    /**
     * Returns image id with tag
     *
     * @return string
     */
    public function getFullImageId()
    {
        return $this->getImageId() . ':' . $this->getTag();
    }

    /**
     * Returns image id in a user-friendly format
     *
     * @return string
     */
    public function getPrintableImageId()
    {
        return $this->stripRegistryName($this->getFullImageId());
    }

    /**
     * @return positive-int
     */
    public function getProcessTimeout(): int
    {
        $componentTimeout = $this->getSourceComponent()->getProcessTimeout();
        $customTimeout = $this->configData['runtime']['process_timeout'] ?? 0;

        if (!$this->isMain() || $customTimeout <= 0) {
            return $componentTimeout;
        }

        return min($customTimeout, self::MAX_CONFIG_TIMEOUT);
    }

    private function stripRegistryName($string)
    {
        $parts = explode('/', $string);
        array_shift($parts);
        return implode('/', $parts);
    }

    /**
     * Prepare the container image so that it can be run.
     *
     * @param array $configData Configuration (same as the one stored in data config file)
     * @throws Exception
     */
    public function prepare(array $configData)
    {
        /**
         * Because we still run images by tag, we need to check that the tag matches the digest.
         * One way to do this can be to docker list images with tag and check their digest. Unfortunately this
         * does not work because of bug https://github.com/docker/cli/issues/728
         *
         * I.e. running
         * `docker images --digests someImage@sha256:someDigest`
         * returns the image digest and no tags, and running
         * `docker images --digests someImage:someTag`
         * return the image tag and no digests. This is also probably in a way intentional as suggested here
         * https://success.docker.com/article/images-tagging-vs-digests
         *
         * Therefor, one would be to do
         * `docker images someImage:someTag --no-trunc --format "{{.ID}}"`
         * and
         * `docker images someImage@sha:someDigest --no-trunc --format "{{.ID}}"`
         * and verify that the IDs match.
         *
         * This requires 2 docker lists which take time, to do it with one command, we can use:
         * `docker image inspect someImage:someTag -f '{{.RepoDigests}}'`
         * which returns a list of digests associated to a given tag. Which is what image->getDigests() does.
         */
        $this->configData = $configData;
        $digests = $this->getImageDigests();
        array_walk($digests, function (&$value) {
            // the value looks like:
            // 061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing@sha256:abcdefghxxxxxxxxxxxxxxxxxxxx
            if (preg_match('#@sha256:(.*)$#', $value, $matches)) {
                $value = $matches[1];
            } else {
                // whatever it is, ignore it silently and download new image copy
                // (this is the case when image does not exist at all)
                $value = '';
            }
        });
        if (!in_array($this->digest, $digests)) {
            $this->logger->notice(
                sprintf('Digest "%s" for image "%s" not found.', $this->digest, $this->getFullImageId()),
            );
            $this->pullImage();
        }
    }

    public function getSourceComponent()
    {
        return $this->component;
    }

    /**
     * Log repository hash (digest) of the image.
    */
    public function logImageHash()
    {
        $this->logger->notice(
            'Using image ' . $this->getFullImageId() . ' with repo-digest ' .
            implode(', ', $this->getImageDigests()),
        );
    }

    /**
     * Get repository hash (digest) of the image.
     * @return string[]
     */
    public function getImageDigests()
    {
        if (empty($this->imageDigests)) {
            $command = 'sudo docker inspect ' . escapeshellarg($this->getFullImageId());
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(3600);
            try {
                $process->mustRun();
                $inspect = json_decode($process->getOutput(), true);
                if ((json_last_error() !== JSON_ERROR_NONE) && !empty($inspect[0]['RepoDigests'])) {
                    throw new InvalidArgumentException('Inspect error ' . json_last_error_msg());
                }
                $this->imageDigests = $inspect[0]['RepoDigests'];
            } catch (Throwable) {
                $this->logger->notice('Failed to get hash for image ' . $this->getFullImageId());
                $this->imageDigests = [];
            }
        }
        return $this->imageDigests;
    }

    /**
     * Return user friendly image digests.
     *
     * @return string[]
     */
    public function getPrintableImageDigests()
    {
        return array_map([$this, 'stripRegistryName'], $this->getImageDigests());
    }

    /**
     * @return RetryProxy
     */
    protected function getRetryProxy()
    {
        $retryPolicy = new SimpleRetryPolicy($this->retryMaxAttempts);
        $backOffPolicy = new ExponentialBackOffPolicy($this->retryMinInterval, 2, $this->retryMaxInterval);
        return new RetryProxy($retryPolicy, $backOffPolicy);
    }
}
