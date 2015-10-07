<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\Syrup\Service\ObjectEncryptor;

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
     * @param ObjectEncryptor $encryptor
     * @param array $config Docker image configuration.
     * @return Image|DockerHub
     */
    public static function factory(ObjectEncryptor $encryptor, $config = [])
    {
        $processedConfig = (new Configuration\Image())->parse(array("config" => $config));
        if (isset($processedConfig["definition"]["type"]) && $processedConfig["definition"]["type"] == "dockerhub") {
            $instance = new Image\DockerHub();
            $instance->setDockerHubImageId($config["definition"]["uri"]);
        } else if (isset($processedConfig["definition"]["type"]) && $processedConfig["definition"]["type"] == "dockerhub-private") {
            $instance = new Image\DockerHub\PrivateRepository($encryptor);
            $instance->setDockerHubImageId($processedConfig["definition"]["uri"]);
        } else {
            $instance = new self();
        }
        $instance->fromArray($processedConfig);
        return $instance;
    }

    /**
     * @param Container $container
     * @return string Image tag name.
     * @throws \Exception
     */
    public function prepare(Container $container)
    {
        throw new \Exception("Not implemented");
    }
}
