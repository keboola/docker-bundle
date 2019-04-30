<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\ObjectEncryptor\ObjectEncryptor;
use Psr\Log\LoggerInterface;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Process\Process;

abstract class Image
{
    /**
     * Image Id
     *
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $imageId;

    /**
     * @var string
     */
    protected $tag = "latest";

    /**
     * @var string
     */
    protected $digest;

    /**
     * @var ObjectEncryptor
     */
    protected $encryptor;

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

    /**
     * @var Component
     */
    private $component;

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

    /**
     * Constructor (use @see {factory()})
     * @param ObjectEncryptor $encryptor
     * @param Component $component
     * @param LoggerInterface $logger
     */
    public function __construct(ObjectEncryptor $encryptor, Component $component, LoggerInterface $logger)
    {
        $this->encryptor = $encryptor;
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
     * @return ObjectEncryptor
     */
    public function getEncryptor()
    {
        return $this->encryptor;
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
        return $this->getImageId() . ":" . $this->getTag();
    }

    /**
     * Prepare the container image so that it can be run.
     *
     * @param array $configData Configuration (same as the one stored in data config file)
     * @throws \Exception
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
//        array_walk($digests, function (&$value) {
//            // the value looks like:
//            // 061240556736.dkr.ecr.us-east-1.amazonaws.com/docker-testing@sha256:abcdefghxxxxxxxxxxxxxxxxxxxx
//            if (preg_match('#@sha256:(.*)$#', $value, $matches)) {
//                $value = $matches[1];
//            } else {
//                // whatever it is, ignore it silently and download new image copy
//                // (this is the case when image does not exist at all)
//                $value = '';
//            }
//        });
//        if (!in_array($this->digest, $digests)) {
//            $this->logger->notice(
//                sprintf('Digest "%s" for image "%s" not found.', $this->digest, $this->getFullImageId())
//            );
            $this->pullImage();
//        }
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
            "Using image " . $this->getFullImageId() . " with repo-digest " .
            implode(', ', $this->getImageDigests())
        );
    }

    /**
     * Get repository hash (digest) of the image.
     * @return string[]
     */
    public function getImageDigests()
    {
        $start = microtime(true);
        var_dump('outer start: ' . microtime(true) . ' ' . $this->getFullImageId());
        if (empty($this->imageDigests)) {
            $command = "sudo docker inspect " . escapeshellarg($this->getFullImageId());
            $process = new Process($command);
            $process->setTimeout(3600);
            try {
                $retryPolicy = new SimpleRetryPolicy(3);
                $backOffPolicy = new ExponentialBackOffPolicy(10000);
                $proxy = new RetryProxy($retryPolicy, $backOffPolicy);
                var_dump('proxy start: ' . microtime(true));
                $proxy->call(function () use ($process) {
                    try {
                        $start = microtime(true);
                        var_dump('inner start: ' . microtime(true));
                        $process->mustRun();
                        var_dump('inner end: ' . microtime(true));
                        $end = microtime(true);
                        var_dump(sprintf('inner: %s', $end - $start));
                    } catch (\Exception $e) {
                        var_dump('exception ' . $e->getMessage());
                        throw $e;
                    }
                });
                $inspect = json_decode($process->getOutput(), true);
                if ((json_last_error() != JSON_ERROR_NONE) && !empty($inspect[0]['RepoDigests'])) {
                    throw new \InvalidArgumentException("Inspect error " . json_last_error_msg());
                }
                var_dump('proxy end: ' . microtime(true));
                $this->imageDigests = $inspect[0]['RepoDigests'];
            } catch (\Exception $e) {
                $this->logger->notice("Failed to get hash for image " . $this->getFullImageId());
                $this->imageDigests = [];
            }
        }
        var_dump('outer end: ' . microtime(true) . ' ' . $this->getFullImageId());
        $end = microtime(true);
        var_dump(sprintf('outer: %s', $end - $start));
        return $this->imageDigests;
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
