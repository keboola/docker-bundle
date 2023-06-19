<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\StagingProvider\Staging\Workspace\AbsWorkspaceStaging;
use Keboola\StagingProvider\Staging\Workspace\RedshiftWorkspaceStaging;
use Keboola\StagingProvider\WorkspaceProviderFactory\ComponentWorkspaceProviderFactory;
use Keboola\StagingProvider\WorkspaceProviderFactory\Configuration\WorkspaceBackendConfig;
use Keboola\StagingProvider\WorkspaceProviderFactory\ExistingDatabaseWorkspaceProviderFactory;
use Keboola\StagingProvider\WorkspaceProviderFactory\ExistingFilesystemWorkspaceProviderFactory;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Psr\Log\LoggerInterface;

class WorkspaceProviderFactoryFactory
{
    /** @var Components */
    private $componentsApiClient;

    /** @var Workspaces */
    private $workspacesApiClient;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Components $componentsApiClient,
        Workspaces $workspacesApiClient,
        LoggerInterface $logger
    ) {
        $this->componentsApiClient = $componentsApiClient;
        $this->workspacesApiClient = $workspacesApiClient;
        $this->logger = $logger;
    }

    public function getWorkspaceProviderFactory(
        $stagingStorage,
        Component $component,
        $configId,
        array $backendConfig,
        ?bool $useReadonlyRole
    ) {
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        if ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_ABS)) {
            // ABS workspaces are persistent, but only if configId is present
            $workspaceProviderFactory = $this->getWorkspaceFactoryForPersistentAbsWorkspace($component, $configId);
        } elseif ($configId && ($stagingStorage === AbstractStrategyFactory::WORKSPACE_REDSHIFT)) {
            // Redshift workspaces are persistent, but only if configId is present
            $workspaceProviderFactory = $this->getWorkspaceFactoryForPersistentRedshiftWorkspace($component, $configId);
        } else {
            $workspaceProviderFactory = new ComponentWorkspaceProviderFactory(
                $this->componentsApiClient,
                $this->workspacesApiClient,
                $component->getId(),
                $configId,
                $this->resolveWorkspaceBackendConfiguration($backendConfig),
                $useReadonlyRole
            );
            $this->logger->notice(sprintf(
                'Created a new %s workspace.',
                $useReadonlyRole ? 'readonly ephemeral' : 'ephemeral'
            ));
        }
        return $workspaceProviderFactory;
    }

    private function getWorkspaceFactoryForPersistentRedshiftWorkspace(
        Component $component,
        $configId
    ): ExistingDatabaseWorkspaceProviderFactory {
        $listOptions = (new ListConfigurationWorkspacesOptions())
            ->setComponentId($component->getId())
            ->setConfigurationId($configId);
        $workspaces = $this->componentsApiClient->listConfigurationWorkspaces($listOptions);

        if (count($workspaces) === 0) {
            $workspace = $this->componentsApiClient->createConfigurationWorkspace(
                $component->getId(),
                $configId,
                ['backend' => RedshiftWorkspaceStaging::getType()],
                true
            );
            $workspaceId = (int) $workspace['id'];
            $password = $workspace['connection']['password'];
            $this->logger->info(sprintf('Created a new persistent workspace "%s".', $workspaceId));
        } elseif (count($workspaces) === 1) {
            $workspaceId = (int) $workspaces[0]['id'];
            $password = $this->workspacesApiClient->resetWorkspacePassword($workspaceId)['password'];
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
                $workspaceId
            ));
            $password = $this->workspacesApiClient->resetWorkspacePassword($workspaceId)['password'];
        }
        return new ExistingDatabaseWorkspaceProviderFactory(
            $this->workspacesApiClient,
            (string) $workspaceId,
            $password
        );
    }

    private function getWorkspaceFactoryForPersistentAbsWorkspace(Component $component, $configId)
    {
        // ABS workspaces are persistent, but only if configId is present
        $listOptions = (new ListConfigurationWorkspacesOptions())
            ->setComponentId($component->getId())
            ->setConfigurationId($configId);
        $workspaces = $this->componentsApiClient->listConfigurationWorkspaces($listOptions);

        if (count($workspaces) === 0) {
            $workspace = $this->componentsApiClient->createConfigurationWorkspace(
                $component->getId(),
                $configId,
                ['backend' => AbsWorkspaceStaging::getType()],
                true
            );
            $workspaceId = (int) $workspace['id'];
            $connectionString = $workspace['connection']['connectionString'];
            $this->logger->info(sprintf('Created a new persistent workspace "%s".', $workspaceId));
        } elseif (count($workspaces) === 1) {
            $workspaceId = (int) $workspaces[0]['id'];
            $connectionString = $this->workspacesApiClient->resetWorkspacePassword($workspaceId)['connectionString'];
            $this->logger->info(sprintf('Reusing persistent workspace "%s".', $workspaceId));
        } else {
            throw new ApplicationException(sprintf(
                'Multiple workspaces (total %s) found (IDs: %s, %s) for configuration "%s" of component "%s".',
                count($workspaces),
                $workspaces[0]['id'],
                $workspaces[1]['id'],
                $configId,
                $component->getId()
            ));
        }
        return new ExistingFilesystemWorkspaceProviderFactory(
            $this->workspacesApiClient,
            (string) $workspaceId,
            $connectionString
        );
    }

    private function resolveWorkspaceBackendConfiguration(array $backendConfig)
    {
        $backendType = $backendConfig['type'] ?? null;
        return new WorkspaceBackendConfig($backendType);
    }
}
