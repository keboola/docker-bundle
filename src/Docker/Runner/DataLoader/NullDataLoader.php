<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
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
        return new StorageState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function storeOutput()
    {
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
}
