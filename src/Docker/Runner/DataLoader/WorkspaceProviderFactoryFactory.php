<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\StagingProvider\Staging\Workspace\AbsWorkspaceStaging;
use Keboola\StagingProvider\Staging\Workspace\BigQueryWorkspaceStaging;
use Keboola\StagingProvider\Staging\Workspace\RedshiftWorkspaceStaging;
use Keboola\StagingProvider\WorkspaceProviderFactory\AbstractCachedWorkspaceProviderFactory;
use Keboola\StagingProvider\WorkspaceProviderFactory\ComponentWorkspaceProviderFactory;
use Keboola\StagingProvider\WorkspaceProviderFactory\Configuration\WorkspaceBackendConfig;
use Keboola\StagingProvider\WorkspaceProviderFactory\Credentials\ABSWorkspaceCredentials;
use Keboola\StagingProvider\WorkspaceProviderFactory\Credentials\BigQueryWorkspaceCredentials;
use Keboola\StagingProvider\WorkspaceProviderFactory\Credentials\CredentialsInterface;
use Keboola\StagingProvider\WorkspaceProviderFactory\Credentials\DatabaseWorkspaceCredentials;
use Keboola\StagingProvider\WorkspaceProviderFactory\ExistingDatabaseWorkspaceProviderFactory;
use Keboola\StagingProvider\WorkspaceProviderFactory\ExistingFilesystemWorkspaceProviderFactory;
use Keboola\StagingProvider\WorkspaceProviderFactory\WorkspaceProviderFactoryInterface;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Psr\Log\LoggerInterface;

class WorkspaceProviderFactoryFactory
{
    public function __construct(
        private readonly Components $componentsApiClient,
        private readonly Workspaces $workspacesApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getWorkspaceProviderFactory(
        string $stagingStorage,
        Component $component,
        ?string $configId,
        array $backendConfig,
        ?bool $useReadonlyRole,
    ): WorkspaceProviderFactoryInterface {
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        if ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_ABS)) {
            // ABS workspaces are persistent, but only if configId is present
            $workspaceProviderFactory = $this->getWorkspaceFactoryForPersistentWorkspace(
                $component,
                $configId,
                AbsWorkspaceStaging::getType(),
                ABSWorkspaceCredentials::class,
                ExistingFilesystemWorkspaceProviderFactory::class,
            );
        } elseif ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_REDSHIFT)) {
            // Redshift workspaces are persistent, but only if configId is present
            $workspaceProviderFactory = $this->getWorkspaceFactoryForPersistentWorkspace(
                $component,
                $configId,
                RedshiftWorkspaceStaging::getType(),
                DatabaseWorkspaceCredentials::class,
                ExistingDatabaseWorkspaceProviderFactory::class,
            );
        /*
         * Persistent BigQuery workspaces are possible, but do not work well on connection side (shared buckets,
         * read-only role). If fixed, uncomment this + add changes to DataLoader class and it will start working.
         *
        } elseif ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_BIGQUERY)) {
            // BigQuery workspaces are persistent, but only if configId is present
            $workspaceProviderFactory = $this->getWorkspaceFactoryForPersistentWorkspace(
                $component,
                $configId,
                BigQueryWorkspaceStaging::getType(),
                BigQueryWorkspaceCredentials::class,
                ExistingDatabaseWorkspaceProviderFactory::class,
            );
        */
        } else {
            $workspaceProviderFactory = new ComponentWorkspaceProviderFactory(
                $this->componentsApiClient,
                $this->workspacesApiClient,
                $component->getId(),
                $configId,
                $this->resolveWorkspaceBackendConfiguration($backendConfig),
                $useReadonlyRole,
            );
            $this->logger->notice(sprintf(
                'Created a new %s workspace.',
                $useReadonlyRole ? 'readonly ephemeral' : 'ephemeral',
            ));
        }
        return $workspaceProviderFactory;
    }

    /**
     * @param class-string<CredentialsInterface> $credentialsClass
     * @param class-string<AbstractCachedWorkspaceProviderFactory> $workspaceFactoryClass
     */
    private function getWorkspaceFactoryForPersistentWorkspace(
        Component $component,
        string $configId,
        string $backendType,
        string $credentialsClass,
        string $workspaceFactoryClass,
    ): AbstractCachedWorkspaceProviderFactory {
        $listOptions = (new ListConfigurationWorkspacesOptions())
            ->setComponentId($component->getId())
            ->setConfigurationId($configId);
        $workspaces = $this->componentsApiClient->listConfigurationWorkspaces($listOptions);

        if (count($workspaces) === 0) {
            $workspace = $this->componentsApiClient->createConfigurationWorkspace(
                $component->getId(),
                $configId,
                ['backend' => $backendType],
                true,
            );
            $workspaceId = (int) $workspace['id'];
            $credentials = $credentialsClass::fromPasswordResetArray($workspace['connection']);
            $this->logger->info(sprintf('Created a new persistent workspace "%s".', $workspaceId));
        } elseif (count($workspaces) === 1) {
            $workspaceId = (int) $workspaces[0]['id'];
            $credentials = $credentialsClass::fromPasswordResetArray(
                $this->workspacesApiClient->resetWorkspacePassword($workspaceId),
            );
            $this->logger->info(sprintf('Reusing persistent workspace "%s".', $workspaceId));
        } else {
            $ids = array_column($workspaces, 'id');
            sort($ids, SORT_NUMERIC);
            $workspaceId = (int) $ids[0];
            $this->logger->warning(sprintf(
                'Multiple workspaces (total %s) found (IDs: %s) for configuration "%s" of component "%s", using "%s".',
                count($workspaces),
                implode(',', $ids),
                $configId,
                $component->getId(),
                $workspaceId,
            ));
            $credentials = $credentialsClass::fromPasswordResetArray(
                $this->workspacesApiClient->resetWorkspacePassword($workspaceId),
            );
        }
        return new $workspaceFactoryClass(
            $this->workspacesApiClient,
            (string) $workspaceId,
            $credentials,
        );
    }

    private function resolveWorkspaceBackendConfiguration(array $backendConfig): WorkspaceBackendConfig
    {
        $backendType = $backendConfig['type'] ?? null;
        return new WorkspaceBackendConfig($backendType);
    }
}
