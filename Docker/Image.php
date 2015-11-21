<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Logger;

class Image
{
    /**
     *
     * Image Id
     *
     * @var string
     */
    protected $id;

    /**
     *
     * Memory allowance
     *
     * @var string
     */
    protected $memory = '64m';

    /**
     *
     * CPU allowance
     *
     * @var int
     */
    protected $cpuShares = 1024;

    /**
     * @var string
     */
    protected $configFormat = 'yaml';

    /**
     * @var bool
     */
    protected $forwardToken = false;

    /**
     * @var bool
     */
    protected $forwardTokenDetails = false;

    /**
     * @var bool
     */
    private $streamingLogs = true;

    /**
     *
     * process timeout in seconds
     *
     * @var int
     */
    protected $processTimeout = 3600;

    /**
     * @var string
     */
    protected $imageId;

    /**
     * @var string
     */
    protected $tag = "latest";


    /**
     * Constructor (use @see {factory()})
     */
    protected function __construct()
    {
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getMemory()
    {
        return $this->memory;
    }

    /**
     * @param string $memory
     * @return $this
     */
    public function setMemory($memory)
    {
        $this->memory = $memory;

        return $this;
    }

    /**
     * @return int
     */
    public function getCpuShares()
    {
        return $this->cpuShares;
    }

    /**
     * @param int $cpuShares
     * @return $this
     */
    public function setCpuShares($cpuShares)
    {
        $this->cpuShares = $cpuShares;

        return $this;
    }

    /**
     * @return string
     */
    public function getConfigFormat()
    {
        return $this->configFormat;
    }

    /**
     * @param $configFormat
     * @return $this
     * @throws \Exception
     */
    public function setConfigFormat($configFormat)
    {
        if (!in_array($configFormat, ['yaml', 'json'])) {
            throw new \Exception("Configuration format '{$configFormat}' not supported");
        }
        $this->configFormat = $configFormat;

        return $this;
    }

    /**
     * @return int
     */
    public function getProcessTimeout()
    {
        return $this->processTimeout;
    }

    /**
     * @param int $timeout
     * @return $this
     */
    public function setProcessTimeout($timeout)
    {
        $this->processTimeout = (int)$timeout;

        return $this;
    }

    /**
     * @param $forwardToken
     * @return $this
     */
    public function setForwardToken($forwardToken)
    {
        $this->forwardToken = $forwardToken;

        return $this;
    }

    /**
     * @return bool
     */
    public function getForwardToken()
    {
        return $this->forwardToken;
    }

    /**
     * @param $forwardTokenDetails
     * @return $this
     */
    public function setForwardTokenDetails($forwardTokenDetails)
    {
        $this->forwardTokenDetails = $forwardTokenDetails;

        return $this;
    }

    /**
     * @return bool
     */
    public function getForwardTokenDetails()
    {
        return $this->forwardTokenDetails;
    }

    /**
     * @param $streamingLogs
     * @return $this
     */
    public function setStreamingLogs($streamingLogs)
    {
        $this->streamingLogs = $streamingLogs;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isStreamingLogs()
    {
        return $this->streamingLogs;
    }

    /**
     * @return string
     */
    public function getImageId()
    {
        return $this->imageId;
    }

    /**
     * @param string $imageId
     * @return $this
     */
    public function setImageId($imageId)
    {
        $this->imageId = $imageId;

        return $this;
    }

    /**
     * @return string
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * @param string $tag
     * @return $this
     */
    public function setTag($tag)
    {
        $this->tag = $tag;

        return $this;
    }

    /**
     * @param array $config
     * @return Image
     * @throws \Exception
     */
    public function fromArray($config = [])
    {
        if (isset($config["id"])) {
            $this->setId($config["id"]);
        }
        if (isset($config["configuration_format"])) {
            $this->setConfigFormat($config["configuration_format"]);
        }
        if (isset($config["cpu_shares"])) {
            $this->setCpuShares($config["cpu_shares"]);
        }
        if (isset($config["memory"])) {
            $this->setMemory($config["memory"]);
        }
        if (isset($config["process_timeout"])) {
            $this->setProcessTimeout($config["process_timeout"]);
        }
        if (isset($config["forward_token"])) {
            $this->setForwardToken($config["forward_token"]);
        }
        if (isset($config["forward_token_details"])) {
            $this->setForwardTokenDetails($config["forward_token_details"]);
        }
        if (isset($config["streaming_logs"])) {
            $this->setStreamingLogs($config["streaming_logs"]);
        }

        return $this;
    }

    /**
     * @param ObjectEncryptor $encryptor Encryptor for image definition.
     * @param Logger $logger Logger instance.
     * @param array $config Docker image runtime configuration.
     * @return Image|DockerHub
     */
    public static function factory(ObjectEncryptor $encryptor, Logger $logger, $config = [])
    {
        $processedConfig = (new Configuration\Image())->parse(array("config" => $config));
        if (isset($processedConfig["definition"]["type"]) && $processedConfig["definition"]["type"] == "dockerhub") {
            $instance = new Image\DockerHub();
        } else {
            if (isset($processedConfig["definition"]["type"]) && $processedConfig["definition"]["type"] == "dockerhub-private") {
                $instance = new Image\DockerHub\PrivateRepository($encryptor);
            } else {
                if (isset($processedConfig["definition"]["type"]) && $processedConfig["definition"]["type"] == "builder") {
                    $instance = new Image\Builder\ImageBuilder($encryptor);
                    $instance->setLogger($logger);
                } elseif (isset($processedConfig["definition"]["type"]) && $processedConfig["definition"]["type"] == "quayio") {
                    $instance = new Image\QuayIO();
                } else {
                    $instance = new self();
                }
            }
        }
        $instance->setImageId($config["definition"]["uri"]);
        if (isset($config["definition"]["tag"])) {
            $instance->setTag($config["definition"]["tag"]);
        }
        $instance->fromArray($processedConfig);

        return $instance;
    }

    /**
     *
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
     * @param Container $container
     * @param array $configData Configuration (user supplied configuration stored in data config file)
     * @param array $volatileConfigData Configuration (user supplied configuration NOT stored in config file)
     * @param string $containerId Container ID
     * @return string Image tag name.
     * @throws \Exception
     */
    public function prepare(Container $container, array $configData, array $volatileConfigData, $containerId)
    {
        throw new \Exception("Not implemented");
    }
}
