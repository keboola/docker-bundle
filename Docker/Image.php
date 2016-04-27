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
     * @var bool
     */
    private $defaultBucket = false;

    /**
     * @var string
     */
    private $defaultBucketStage = "in";

    /**
     * @var array
     */
    private $imageParameters = [];

    /**
     * @var string
     */
    private $networkType = 'bridge';

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
     * @var ObjectEncryptor
     */
    protected $encryptor;


    /**
     * Constructor (use @see {factory()})
     * @param ObjectEncryptor $encryptor
     */
    public function __construct(ObjectEncryptor $encryptor)
    {
        $this->setEncryptor($encryptor);
    }

    /**
     * @return ObjectEncryptor
     */
    public function getEncryptor()
    {
        return $this->encryptor;
    }

    /**
     * @param ObjectEncryptor $encryptor
     * @return $this
     */
    public function setEncryptor($encryptor)
    {
        $this->encryptor = $encryptor;
        return $this;
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
     * @param bool $streamingLogs
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
     * @param $imageParameters
     * @return $this
     */
    public function setImageParameters($imageParameters)
    {
        $this->imageParameters = $imageParameters;
        return $this;
    }

    /**
     * @return array
     */
    public function getImageParameters()
    {
        return $this->imageParameters;
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
     * @return boolean|string
     */
    public function isDefaultBucket()
    {
        return $this->defaultBucket;
    }

    /**
     * @param boolean|string $defaultBucket
     * @return $this
     */
    public function setDefaultBucket($defaultBucket)
    {
        $this->defaultBucket = $defaultBucket;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getDefaultBucketStage()
    {
        return $this->defaultBucketStage;
    }

    /**
     * @param mixed $defaultBucketStage
     * @return $this
     */
    public function setDefaultBucketStage($defaultBucketStage)
    {
        $this->defaultBucketStage = $defaultBucketStage;
        return $this;
    }

    /**
     * @return string
     */
    public function getNetworkType()
    {
        return $this->networkType;
    }

    /**
     * @param string $networkType
     * @return $this
     */
    public function setNetworkType($networkType)
    {
        $this->networkType = $networkType;
        return $this;
    }

    /**
     * @param array $config
     * @return Image
     * @throws \Exception
     */
    public function fromArray($config = [])
    {
        $fields = ['id' => 'setId', 'configuration_format' => 'setConfigFormat', 'cpu_shares' => 'setCpuShares',
            'memory' => 'setMemory', 'process_timeout' => 'setProcessTimeout', 'forward_token' => 'setForwardToken',
            'forward_token_details' => 'setForwardTokenDetails', 'streaming_logs' => 'setStreamingLogs',
            'default_bucket' => 'setDefaultBucket', 'default_bucket_stage' => 'setDefaultBucketStage',
            'image_parameters' => 'setImageParameters', 'network' => 'setNetworkType',
        ];
        foreach ($fields as $fieldName => $methodName) {
            if (isset($config[$fieldName])) {
                $this->$methodName($config[$fieldName]);
            }
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
            $instance = new Image\DockerHub($encryptor);
        } else {
            if (isset($processedConfig["definition"]["type"]) &&
                $processedConfig["definition"]["type"] == "dockerhub-private"
            ) {
                $instance = new Image\DockerHub\PrivateRepository($encryptor);
            } elseif (isset($processedConfig["definition"]["type"]) &&
                $processedConfig["definition"]["type"] == "quayio-private"
            ) {
                $instance = new Image\QuayIO\PrivateRepository($encryptor);

            } else {
                if (isset($processedConfig["definition"]["type"]) &&
                    $processedConfig["definition"]["type"] == "builder"
                ) {
                    $instance = new Image\Builder\ImageBuilder($encryptor);
                    $instance->setLogger($logger);
                } elseif (isset($processedConfig["definition"]["type"]) &&
                    $processedConfig["definition"]["type"] == "quayio"
                ) {
                    $instance = new Image\QuayIO($encryptor);
                } else {
                    $instance = new self($encryptor);
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
     * @param array $configData Configuration (same as the one stored in data config file)
     * @param string $containerId Container ID
     * @return string Image tag name.
     * @throws \Exception
     */
    public function prepare(Container $container, array $configData, $containerId)
    {
        throw new \Exception("Not implemented");
    }
}
