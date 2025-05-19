<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\StagingProvider\Exception\NoStagingAvailableException;
use Keboola\StagingProvider\Staging\StagingProvider;
use Keboola\StagingProvider\Staging\Workspace\LazyWorkspaceStaging;
use Keboola\StagingProvider\Workspace\WorkspaceInterface;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StagingProvider\Workspace\WorkspaceWithCredentialsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class StagingWorkspaceFacade
{
    public function __construct(
        private readonly WorkspaceProvider $workspaceProvider,
        private readonly StagingProvider $stagingProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getWorkspaceId(): ?string
    {
        return $this->getRealWorkspace()?->getWorkspaceId();
    }

    public function getBackendSize(): ?string
    {
        return $this->getRealWorkspace()?->getBackendSize();
    }

    public function getCredentials(): array
    {
        $workspace = $this->getRealWorkspace();
        if (!$workspace instanceof WorkspaceWithCredentialsInterface) {
            return [];
        }

        return $workspace->getCredentials();
    }

    public function cleanup(): void
    {
        $workspace = $this->getLazyWorkspace();
        if ($workspace === null) {
            return;
        }

        if ($workspace instanceof LazyWorkspaceStaging && !$workspace->isInitialized()) {
            return;
        }

        try {
            $this->workspaceProvider->cleanupWorkspace($workspace->getWorkspaceId());
        } catch (Throwable $e) {
            // ignore errors if the cleanup fails because we a) can't fix it b) should not break the job
            $this->logger->error('Failed to cleanup workspace: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function getLazyWorkspace(): ?WorkspaceInterface
    {
        try {
            $staging = $this->stagingProvider->getTableDataStaging();
        } catch (NoStagingAvailableException) {
            return null;
        }

        if (!$staging instanceof WorkspaceInterface) {
            return null;
        }

        return $staging;
    }

    private function getRealWorkspace(): ?WorkspaceInterface
    {
        $workspace = $this->getLazyWorkspace();
        while ($workspace instanceof LazyWorkspaceStaging) {
            $workspace = $workspace->getWorkspace();
        }
        return $workspace;
    }
}
