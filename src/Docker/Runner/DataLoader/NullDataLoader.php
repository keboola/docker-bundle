<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Result;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;

class NullDataLoader implements DataLoaderInterface
{
    public function loadInputData(
        InputTableStateList $inputTableStateList,
        InputFileStateList $inputFileStateList,
    ): StorageState {
        $result = new Result();
        $result->setInputTableStateList(new InputTableStateList([]));
        return new StorageState($result, new InputFileStateList([]));
    }

    public function storeOutput(bool $isFailedJob = false): ?LoadTableQueue
    {
        return null;
    }

    public function storeDataArchive(string $jobId, string $configRowId, string $fileName): void
    {
    }

    public function getWorkspaceCredentials(): array
    {
        return [];
    }

    public function cleanWorkspace(): void
    {
    }

    public function getWorkspaceBackendSize(): ?string
    {
        return null;
    }
}
