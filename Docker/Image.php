<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\Gelf\ServerFactory;
use Keboola\Syrup\Exception\ApplicationException;
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
    private $networkType = 'bridge';

    /**
     * Process timeout in seconds
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
     * @var string
     */
    private $loggerType = 'standard';

    /**
     * @var array
     */
    private $loggerVerbosity = [];

    /**
     * @var string
     */
    private $loggerServerType = 'tcp';

    /**
     * @var array
     */
    private $configData;

    /**
     * @var bool
     */
    private $isMain;


    /**
     * Constructor (use @see {factory()})
     * @param ObjectEncryptor $encryptor
     */
    public function __construct(ObjectEncryptor $encryptor)
    {
        $this->encryptor = $encryptor;
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
    public function getMemory()
    {
        return $this->memory;
    }

    /**
     * @param string $memory
     */
    public function setMemory($memory)
    {
        $this->memory = $memory;
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
     */
    public function setCpuShares($cpuShares)
    {
        $this->cpuShares = $cpuShares;
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
     */
    public function setImageId($imageId)
    {
        $this->imageId = $imageId;
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
     */
    public function setTag($tag)
    {
        $this->tag = $tag;
    }

    /**
     * @param bool $isMain
     */
    public function setIsMain($isMain)
    {
        $this->isMain = $isMain;
    }

    public function getIsMain()
    {
        return $this->isMain;
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
        if (($networkType != 'none') && ($networkType != 'bridge')) {
            throw new ApplicationException("Network type '$networkType' is not supported.");
        }
        $this->networkType = $networkType;
        return $this;
    }

    /**
     * @param array $logger
     * @return $this
     * @throws \Exception
     */
    public function setLoggerOptions($logger)
    {
        $this->loggerType = $logger['type'];
        $this->loggerVerbosity = $logger['verbosity'];
        switch ($logger['gelf_server_type']) {
            case 'udp':
                $this->loggerServerType = ServerFactory::SERVER_UDP;
                break;
            case 'tcp':
                $this->loggerServerType = ServerFactory::SERVER_TCP;
                break;
            case 'http':
                $this->loggerServerType = ServerFactory::SERVER_HTTP;
                break;
            default:
                throw new ApplicationException("Server type '{$logger['gelf_type']}' not supported");
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getLoggerType()
    {
        return $this->loggerType;
    }

    /**
     * @return mixed
     */
    public function getLoggerVerbosity()
    {
        return $this->loggerVerbosity;
    }

    /**
     * @return mixed
     */
    public function getLoggerServerType()
    {
        return $this->loggerServerType;
    }

    /**
     * @param array $config
     * @return Image
     * @throws \Exception
     */
    public function fromArray($config = [])
    {
        $fields = ['cpu_shares' => 'setCpuShares', 'memory' => 'setMemory', 'process_timeout' => 'setProcessTimeout',
            'network' => 'setNetworkType', 'logging' => 'setLoggerOptions'
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
     * @param bool $isMain True to mark the image as main image.
     * @return Image|DockerHub
     */
    public static function factory(ObjectEncryptor $encryptor, Logger $logger, array $config, $isMain)
    {
        $processedConfig = (new Configuration\Component())->parse(["config" => $config]);
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
        $instance->setIsMain($isMain);

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

    public function getConfigData()
    {
        return $this->configData;
    }

    /**
     * Prepare the container image so that it can be run.
     *
     * @param array $configData Configuration (same as the one stored in data config file)
     * @return string Image tag name.
     * @throws \Exception
     */
    public function prepare(array $configData)
    {
        $this->configData = $configData;
        //throw new \Exception("Not implemented");
    }
}
