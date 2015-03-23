<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;

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
     *
     * process timeout in seconds
     *
     * @var int
     */
    protected $processTimeout = 3600;

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
        if (!in_array($configFormat, array('yaml', 'json'))) {
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
     * @param array $config
     * @return Image|DockerHub
     */
    public static function factory($config = array())
    {
        if (isset($config["definition"]["type"]) && $config["definition"]["type"] == "dockerhub") {
            $instance = new DockerHub();
            $instance->setDockerHubImageId($config["definition"]["uri"]);
        } else {
            $instance = new self;
        }
        if (isset($config["id"])) {
            $instance->setId($config["id"]);
        }
        if (isset($config["cpu_shares"])) {
            $instance->setCpuShares($config["cpu_shares"]);
        }
        if (isset($config["memory"])) {
            $instance->setMemory($config["memory"]);
        }
        if (isset($config["process_timeout"])) {
            $instance->setProcessTimeout($config["process_timeout"]);
        }

        return $instance;
    }

    /**
     * @param Container $container
     * @throws \Exception
     */
    public function prepare(Container $container)
    {
        throw new \Exception("Not implemented");
    }
}
