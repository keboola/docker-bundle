<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\StagingProvider\Provider\AbstractWorkspaceProvider;
use Keboola\StagingProvider\Provider\Configuration\WorkspaceBackendConfig;
use Keboola\StagingProvider\Provider\Credentials\ABSWorkspaceCredentials;
use Keboola\StagingProvider\Provider\Credentials\CredentialsInterface;
use Keboola\StagingProvider\Provider\Credentials\DatabaseWorkspaceCredentials;
use Keboola\StagingProvider\Provider\ExistingWorkspaceStagingProvider;
use Keboola\StagingProvider\Provider\NewWorkspaceStagingProvider;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Psr\Log\LoggerInterface;

class WorkspaceProviderFactory
{
    public function __construct(
        private readonly Components $componentsApiClient,
        private readonly Workspaces $workspacesApiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getWorkspaceStaging(
        string $stagingStorage,
        Component $component,
        ?string $configId,
        array $backendConfig,
        ?bool $useReadonlyRole,
        ?ExternallyManagedWorkspaceCredentials $externallyManagedWorkspaceCredentials,
    ): AbstractWorkspaceProvider {
        if ($externallyManagedWorkspaceCredentials) {
            // Externally managed workspaces are persistent
            $workspaceStaging = new ExistingWorkspaceStagingProvider(
                $this->workspacesApiClient,
                $externallyManagedWorkspaceCredentials->id,
                $externallyManagedWorkspaceCredentials->getDatabaseCredentials(),
            );
            $this->logger->notice(sprintf(
                'Using provided workspace "%s".',
                $externallyManagedWorkspaceCredentials->id,
            ));
        } elseif ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_ABS)) {
            // ABS workspaces are persistent, but only if configId is present
            $workspaceStaging = $this->getPersistentWorkspace(
                $component,
                $configId,
                'abs',
                ABSWorkspaceCredentials::class,
            );
        } elseif ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_REDSHIFT)) {
            // Redshift workspaces are persistent, but only if configId is present
            $workspaceStaging = $this->getPersistentWorkspace(
                $component,
                $configId,
                'redshift',
                DatabaseWorkspaceCredentials::class,
            );
            /*
             * Persistent BigQuery workspaces are possible, but do not work well on connection side (shared buckets,
             * read-only role). If fixed, uncomment this + add changes to DataLoader class and it will start working.
             *
            } elseif ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_BIGQUERY)) {
                // BigQuery workspaces are persistent, but only if configId is present
                $workspaceProviderFactory = $this->getPersistentWorkspace(
                    $component,
                    $configId,
                    'bigquery,
                    BigQueryWorkspaceCredentials::class,
                );
            */
        } else {
            $workspaceStaging = new NewWorkspaceStagingProvider(
                $this->workspacesApiClient,
                $this->componentsApiClient,
                $this->getWorkspaceBackendConfig($backendConfig, $stagingStorage, $useReadonlyRole),
                $component->getId(),
                $configId,
            );
            $this->logger->notice(sprintf(
                'Created a new %s workspace.',
                $useReadonlyRole ? 'readonly ephemeral' : 'ephemeral',
            ));
        }
        return $workspaceStaging;
    }

    /**
     * @param class-string<CredentialsInterface> $credentialsClass
     */
    private function getPersistentWorkspace(
        Component $component,
        string $configId,
        string $workspaceBackend,
        string $credentialsClass,
    ): ExistingWorkspaceStagingProvider {
        $listOptions = (new ListConfigurationWorkspacesOptions())
            ->setComponentId($component->getId())
            ->setConfigurationId($configId);
        $workspaces = $this->componentsApiClient->listConfigurationWorkspaces($listOptions);

        if (count($workspaces) === 0) {
            $workspace = $this->componentsApiClient->createConfigurationWorkspace(
                $component->getId(),
                $configId,
                ['backend' => $workspaceBackend],
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

        return new ExistingWorkspaceStagingProvider(
            $this->workspacesApiClient,
            (string) $workspaceId,
            $credentials,
        );
    }

    private function getWorkspaceBackendConfig(
        array $backendConfig,
        string $stagingStorage,
        ?bool $useReadonlyRole,
    ): WorkspaceBackendConfig {
        return new WorkspaceBackendConfig($stagingStorage, $backendConfig['type'] ?? null, $useReadonlyRole);
    }
}
