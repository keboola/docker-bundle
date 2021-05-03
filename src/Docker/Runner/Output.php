<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
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
     * @var LoadTableQueue|null
     */
    private $tableQueue;
    /**
     * @var StateFile
     */
    private $stateFile;
    /**
     * @var InputTableStateList
     */
    private $inputTableStateList;
    /**
     * @var InputFileStateList
     */
    private $inputFileStateList;

    /**
     * @var DataLoaderInterface
     */
    private $dataLoader;

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
        $this->stateFile = $stateFile;
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

    /**
     * @param InputTableStateList $inputTableStateList
     */
    public function setInputTableStateList(InputTableStateList $inputTableStateList)
    {
        $this->inputTableStateList = $inputTableStateList;
    }

    /**
     * @param InputFileStateList $inputFileStateList
     */
    public function setInputFileStateList(InputFileStateList $inputFileStateList)
    {
        $this->inputFileStateList = $inputFileStateList;
    }

    public function setDataLoader(DataLoaderInterface $dataLoader)
    {
        return $this->dataLoader = $dataLoader;
    }

    /**
     * @return InputTableStateList
     */
    public function getInputTableStateList()
    {
        return $this->inputTableStateList;
    }

    /**
     * @return InputFileStateList
     */
    public function getInputFileStateList()
    {
        return $this->inputFileStateList;
    }

    /**
     * @return LoadTableQueue|null
     */
    public function getTableQueue()
    {
        return $this->tableQueue;
    }

    public function getStateFile()
    {
        return $this->stateFile;
    }

    /**
     * @return DataLoaderInterface
     */
    public function getDataLoader()
    {
        return $this->dataLoader;
    }
}
