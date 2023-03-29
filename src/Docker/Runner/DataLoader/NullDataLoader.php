<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Runner\StorageState;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Result;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;

class NullDataLoader implements DataLoaderInterface
{
    public function loadInputData(): StorageState
    {
        $result = new Result();
        $result->setInputTableStateList(new InputTableStateList([]));
        return new StorageState($result, new InputFileStateList([]));
    }

    public function storeOutput(bool $isFailedJob = false): ?LoadTableQueue
    {
        return null;
    }

    public function getWorkspaceCredentials(): array
    {
        return [];
    }

    public function cleanWorkspace(): void
    {
    }

    public function storeDataArchive(string $fileName, array $tags): void
    {
    }
}
