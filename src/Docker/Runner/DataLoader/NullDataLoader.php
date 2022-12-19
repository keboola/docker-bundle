<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Result;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;

class NullDataLoader implements DataLoaderInterface
{
    public function __construct(
        ClientWrapper $clientWrapper,
        LoggerInterface $logger,
        $dataDirectory,
        JobDefinition $jobDefinition,
        OutputFilterInterface $outputFilter
    ) {
    }

    public function loadInputData(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList
    ) {
        $result = new Result();
        $result->setInputTableStateList(new InputTableStateList([]));
        return new StorageState($result, new InputFileStateList([]));
    }

    public function storeOutput($isFailedJob)
    {
        return null;
    }

    public function storeDataArchive($fileName, array $tags)
    {
    }

    public function getWorkspaceCredentials()
    {
        return [];
    }

    public function cleanWorkspace()
    {
    }

    public function getWorkspaceBackendSize(): ?string
    {
        return null;
    }
}
