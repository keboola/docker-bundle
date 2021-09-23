<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\Table\Result as InputTableResult;

class StorageState
{
    /** @var InputTableResult */
    private $inputTableResult;

    /** @var InputFileStateList */
    private $inputFileStateList;

    public function __construct(
        InputTableResult $inputTableResult,
        InputFileStateList $inputFileStateList
    ) {
        $this->inputTableResult = $inputTableResult;
        $this->inputFileStateList = $inputFileStateList;
    }

    /**
     * @return InputFileStateList
     */
    public function getInputFileStateList()
    {
        return $this->inputFileStateList;
    }

    /**
     * @return InputTableResult
     */
    public function getInputTableResult()
    {
        return $this->inputTableResult;
    }
}
