<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StagingProvider\Workspace\WorkspaceWithCredentialsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class StagingWorkspaceFacade
{
    public function __construct(
        private readonly WorkspaceProvider $workspaceProvider,
        private readonly LoggerInterface $logger,
        private readonly WorkspaceWithCredentialsInterface $workspace,
        private readonly bool $isReusable,
    ) {
    }

    public function getWorkspaceId(): ?string
    {
        return $this->workspace->getWorkspaceId();
    }

    public function getBackendSize(): ?string
    {
        return $this->workspace->getBackendSize();
    }

    public function getCredentials(): array
    {
        return $this->workspace->getCredentials();
    }

    public function cleanup(): void
    {
        if ($this->isReusable) {
            return;
        }

        try {
            $this->workspaceProvider->cleanupWorkspace($this->workspace->getWorkspaceId());
        } catch (Throwable $e) {
            // ignore errors if the cleanup fails because we a) can't fix it b) should not break the job
            $this->logger->error('Failed to cleanup workspace: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
