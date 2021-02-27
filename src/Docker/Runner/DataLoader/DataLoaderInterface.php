<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;

interface DataLoaderInterface
{
    public function __construct(
        ClientWrapper $clientWrapper,
        LoggerInterface $logger,
        $dataDirectory,
        JobDefinition $jobDefinition,
        OutputFilterInterface $outputFilter
    );

    /**
     * @return InputTableStateList
     */
    public function loadInputData(InputTableStateList $inputTableStateList);

    /**
     * @return LoadTableQueue|null
     */
    public function storeOutput();

    public function storeDataArchive($fileName, array $tags);

    /**
     * @return array
     */
    public function getWorkspaceCredentials();

    public function cleanWorkspace();
}
