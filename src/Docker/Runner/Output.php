<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\Table\Result as InputTableResult;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Table\Result as OutputTableResult;

class Output
{
    private array $images = [];
    private string $output;
    private ?string $configVersion = null;
    private ?LoadTableQueue $tableQueue = null;
    private StateFile $stateFile;
    private ?InputFileStateList $inputFileStateList = null;
    private ?DataLoaderInterface $dataLoader = null;
    private ?InputTableResult $inputTableResult = null;
    private ?OutputTableResult $outputTableResult = null;
    private array $artifactsDownloaded = [];
    private array $artifactsUploaded = [];

    public function setImages(array $images): void
    {
        $this->images = $images;
    }

    public function getImages(): array
    {
        return $this->images;
    }

    public function setOutput(string $output): void
    {
        $this->output = $output;
    }

    public function getProcessOutput(): string
    {
        return $this->output;
    }

    public function setConfigVersion(?string $configVersion): void
    {
        $this->configVersion = $configVersion;
    }

    public function getConfigVersion(): ?string
    {
        return $this->configVersion;
    }

    public function setTableQueue(?LoadTableQueue $tableQueue): void
    {
        $this->tableQueue = $tableQueue;
    }

    public function setInputTableResult(InputTableResult $inputTableResult): void
    {
        $this->inputTableResult = $inputTableResult;
    }

    public function setInputFileStateList(InputFileStateList $inputFileStateList): void
    {
        $this->inputFileStateList = $inputFileStateList;
    }

    public function setDataLoader(DataLoaderInterface $dataLoader): void
    {
        $this->dataLoader = $dataLoader;
    }

    public function getInputTableResult(): ?InputTableResult
    {
        return $this->inputTableResult;
    }

    public function getInputFileStateList(): ?InputFileStateList
    {
        return $this->inputFileStateList;
    }

    public function getTableQueue(): ?LoadTableQueue
    {
        return $this->tableQueue;
    }

    public function setStateFile(StateFile $stateFile): void
    {
        $this->stateFile = $stateFile;
    }

    public function getStateFile(): StateFile
    {
        return $this->stateFile;
    }

    public function getDataLoader(): ?DataLoaderInterface
    {
        return $this->dataLoader;
    }

    public function setArtifactsDownloaded(array $downloadedArtifacts): void
    {
        $this->artifactsDownloaded = $downloadedArtifacts;
    }

    public function getArtifactsDownloaded(): array
    {
        return $this->artifactsDownloaded;
    }

    public function setArtifactsUploaded(array $uploadedArtifacts): void
    {
        $this->artifactsUploaded = $uploadedArtifacts;
    }

    public function getArtifactsUploaded(): array
    {
        return $this->artifactsUploaded;
    }

    public function setOutputTableResult(OutputTableResult $outputTableResult): void
    {
        $this->outputTableResult = $outputTableResult;
    }

    public function getOutputTableResult(): ?OutputTableResult
    {
        return $this->outputTableResult;
    }
}
