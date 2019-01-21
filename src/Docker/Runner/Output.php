<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;

class Output
{
    /**
     * @var array
     */
    private $images = [];

    /**
     * @var string
     */
    private $output;

    /**
     * @var string
     */
    private $configVersion;

    /**
     * @var ?LoadTableQueue
     */
    private $tableQueue;

    /**
     * @var StateFile
     */
    private $stateFile;

    /**
     * Output constructor.
     *
     * @param array $images
     * @param string $output
     * @param $configVersion
     */
    public function __construct(array $images, $output, $configVersion, $stateFile)
    {
        $this->images = $images;
        $this->output = $output;
        $this->configVersion = $configVersion;
        $this->stateFile  = $stateFile;
    }

    /**
     * @return array
     */
    public function getImages()
    {
        return $this->images;
    }

    /**
     * @return string
     */
    public function getProcessOutput()
    {
        return $this->output;
    }

    /**
     * @return string
     */
    public function getConfigVersion()
    {
        return $this->configVersion;
    }

    /**
     * @param LoadTableQueue|null $tableQueue
     */
    public function setTableQueue($tableQueue)
    {
        $this->tableQueue = $tableQueue;
    }

    public function getTableQueue()
    {
        return $this->tableQueue;
    }

    public function getStateFile()
    {
        return $this->stateFile;
    }
}
