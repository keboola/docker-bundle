<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\Table\Result as InputTableResult;

class StorageState
{
    private InputTableResult $inputTableResult;
    private InputFileStateList $inputFileStateList;

    public function __construct(
        InputTableResult $inputTableResult,
        InputFileStateList $inputFileStateList,
    ) {
        $this->inputTableResult = $inputTableResult;
        $this->inputFileStateList = $inputFileStateList;
    }

    public function getInputFileStateList(): InputFileStateList
    {
        return $this->inputFileStateList;
    }

    public function getInputTableResult(): InputTableResult
    {
        return $this->inputTableResult;
    }
}
