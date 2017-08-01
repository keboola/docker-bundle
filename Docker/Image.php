<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
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
        $this->imageId = $component->getImageDefinition()["uri"];
        if (!empty($component->getImageDefinition()['tag'])) {
            $this->tag = $component->getImageDefinition()['tag'];
        }
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
        $this->configData = $configData;
        $this->pullImage();
    }

    public function getSourceComponent()
    {
        return $this->component;
    }

    /**
     * Get and log hash of the image.
     * @param string $name Image name including tag
     */
    public function logImageHash($name)
    {
        $retryPolicy = new SimpleRetryPolicy(3);
        $backOffPolicy = new ExponentialBackOffPolicy(10000);
        $proxy = new RetryProxy($retryPolicy, $backOffPolicy);
        $command = "sudo docker images --format \"{{.ID}}\" --no-trunc " . escapeshellarg($name);

        $process = new Process($command);
        $process->setTimeout(3600);
        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
            $this->logger->notice("Using image $name with hash " . trim($process->getOutput()));
        } catch (\Exception $e) {
            $this->logger->error("Failed to get hash for image " . $name);
        }
    }
}
