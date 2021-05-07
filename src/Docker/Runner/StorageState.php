<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;

class StorageState
{
    /** @var InputFileStateList */
    private $inputFileStateList;

    /** @var InputTableStateList */
    private $inputTableStateList;

    public function __construct(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList
    ) {
        $this->inputTableStateList = $inputTableStateList;
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
     * @return InputTableStateList
     */
    public function getInputTableStateList()
    {
        return $this->inputTableStateList;
    }
}
