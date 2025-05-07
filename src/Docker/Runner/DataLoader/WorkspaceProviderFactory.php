<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\StagingProvider\Provider\Configuration\NetworkPolicy;
use Keboola\StagingProvider\Provider\Configuration\WorkspaceBackendConfig;
use Keboola\StagingProvider\Provider\Credentials\ExistingCredentialsProvider;
use Keboola\StagingProvider\Provider\Credentials\ResetCredentialsProvider;
use Keboola\StagingProvider\Provider\ExistingWorkspaceProvider;
use Keboola\StagingProvider\Provider\InvalidWorkspaceProvider;
use Keboola\StagingProvider\Provider\NewWorkspaceProvider;
use Keboola\StagingProvider\Provider\SnowflakeKeypairGenerator;
use Keboola\StagingProvider\Provider\WorkspaceProviderInterface;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApi\Workspaces;
use Psr\Log\LoggerInterface;

class WorkspaceProviderFactory
{
    public function __construct(
        private readonly Components $componentsApiClient,
        private readonly Workspaces $workspacesApiClient,
        private readonly SnowflakeKeypairGenerator $snowflakeKeypairGenerator,
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
    ): WorkspaceProviderInterface {
        if (!in_array($stagingStorage, AbstractStrategyFactory::WORKSPACE_TYPES, true)) {
            return new InvalidWorkspaceProvider($stagingStorage);
        }

        if ($externallyManagedWorkspaceCredentials) {
            // Externally managed workspaces are persistent
            $workspaceStaging = new ExistingWorkspaceProvider(
                $this->workspacesApiClient,
                $externallyManagedWorkspaceCredentials->id,
                new ExistingCredentialsProvider(
                    $externallyManagedWorkspaceCredentials->getWorkspaceCredentials(),
                ),
            );
            $this->logger->notice(sprintf(
                'Using provided workspace "%s".',
                $externallyManagedWorkspaceCredentials->id,
            ));
            return $workspaceStaging;
        }

        $workspaceLoginType = null;
        if ($stagingStorage === AbstractStrategyFactory::WORKSPACE_SNOWFLAKE && $component->useSnowflakeKeyPairAuth()) {
            $workspaceLoginType = WorkspaceLoginType::SNOWFLAKE_SERVICE_KEYPAIR;
        }

        $workspaceBackendConfig = new WorkspaceBackendConfig(
            $stagingStorage,
            $backendConfig['type'] ?? null,
            $useReadonlyRole,
            NetworkPolicy::SYSTEM,
            $workspaceLoginType,
        );

        // ABS & Redshift workspaces are persistent, but only if configId is present
        // Persistent BigQuery workspaces are possible, but do not work well on connection side (shared buckets,
        // read-only role). If fixed, uncomment this + add changes to DataLoader class and it will start working.
        if ($configId && in_array($stagingStorage, [
            AbstractStrategyFactory::WORKSPACE_ABS,
            AbstractStrategyFactory::WORKSPACE_REDSHIFT,
            // AbstractStrategyFactory::WORKSPACE_BIGQUERY,
        ], true)) {
            return $this->getPersistentWorkspace(
                $component,
                $configId,
                $workspaceBackendConfig,
            );
        }

        $this->logger->notice(sprintf(
            'Creating a new %s workspace.',
            $useReadonlyRole ? 'readonly ephemeral' : 'ephemeral',
        ));

        return new NewWorkspaceProvider(
            $this->workspacesApiClient,
            $this->componentsApiClient,
            $this->snowflakeKeypairGenerator,
            $workspaceBackendConfig,
            $component->getId(),
            $configId,
        );
    }

    private function getPersistentWorkspace(
        Component $component,
        string $configId,
        WorkspaceBackendConfig $workspaceBackendConfig,
    ): WorkspaceProviderInterface {
        $listOptions = (new ListConfigurationWorkspacesOptions())
            ->setComponentId($component->getId())
            ->setConfigurationId($configId);
        $workspaces = $this->componentsApiClient->listConfigurationWorkspaces($listOptions);

        if (count($workspaces) === 0) {
            $this->logger->info('Creating a new persistent workspace');

            return new NewWorkspaceProvider(
                $this->workspacesApiClient,
                $this->componentsApiClient,
                $this->snowflakeKeypairGenerator,
                $workspaceBackendConfig,
                $component->getId(),
                $configId,
            );
        }

        if (count($workspaces) === 1) {
            $workspaceId = (string) $workspaces[0]['id'];
            $this->logger->info(sprintf('Reusing persistent workspace "%s".', $workspaceId));
        } else {
            $ids = array_column($workspaces, 'id');
            sort($ids, SORT_NUMERIC);
            $workspaceId = (string) $ids[0];
            $this->logger->warning(sprintf(
                'Multiple workspaces (total %s) found (IDs: %s) for configuration "%s" of component "%s", using "%s".',
                count($workspaces),
                implode(',', $ids),
                $configId,
                $component->getId(),
                $workspaceId,
            ));
        }

        return new ExistingWorkspaceProvider(
            $this->workspacesApiClient,
            $workspaceId,
            new ResetCredentialsProvider(
                $this->workspacesApiClient,
                $this->snowflakeKeypairGenerator,
            ),
        );
    }
}
