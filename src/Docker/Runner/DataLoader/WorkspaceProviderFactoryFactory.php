<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\StagingProvider\Staging\Workspace\AbsWorkspaceStaging;
use Keboola\StagingProvider\Staging\Workspace\RedshiftWorkspaceStaging;
use Keboola\StagingProvider\WorkspaceProviderFactory\Configuration\WorkspaceBackendConfig;
use Keboola\StagingProvider\WorkspaceProviderFactory\ExistingFilesystemWorkspaceProviderFactory;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StagingProvider\WorkspaceProviderFactory\ComponentWorkspaceProviderFactory;
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
        array $backendConfig
    ) {
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        if ($configId && ($stagingStorage === InputStrategyFactory::WORKSPACE_ABS)) {
            // ABS workspaces are persistent, but only if configId is present
            $workspaceProviderFactory = $this->getWorkspaceFactoryForPersistentAbsWorkspace($component, $configId);
        } else {
            $workspaceProviderFactory = new ComponentWorkspaceProviderFactory(
                $this->componentsApiClient,
                $this->workspacesApiClient,
                $component->getId(),
                $configId,
                $this->resolveWorkspaceBackendConfiguration($backendConfig)
            );
            $this->logger->info('Created a new ephemeral workspace.');
        }
        return $workspaceProviderFactory;
    }

    private function getWorkspaceFactoryForRedshiftWorkspace(Component $component, $configId)
    {
        $listOptions = (new ListConfigurationWorkspacesOptions())
            ->setComponentId($component->getId())
            ->setConfigurationId($configId);
        $workspaces = $this->componentsApiClient->listConfigurationWorkspaces($listOptions);

        if (count($workspaces) === 0) {
            $workspace = $this->componentsApiClient->createConfigurationWorkspace(
                $component->getId(),
                $configId,
                ['backend' => RedshiftWorkspaceStaging::getType()]
            );
            $workspaceId = $workspace['id'];
            $connectionString = $workspace['connection']['connectionString'];
            $this->logger->info(sprintf('Created a new persistent workspace "%s".', $workspaceId));
        } elseif (count($workspaces) === 1) {
            $workspaceId = $workspaces[0]['id'];
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
            $workspaceId,
            $connectionString
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
                ['backend' => AbsWorkspaceStaging::getType()]
            );
            $workspaceId = $workspace['id'];
            $connectionString = $workspace['connection']['connectionString'];
            $this->logger->info(sprintf('Created a new persistent workspace "%s".', $workspaceId));
        } elseif (count($workspaces) === 1) {
            $workspaceId = $workspaces[0]['id'];
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
            $workspaceId,
            $connectionString
        );
    }

    private function resolveWorkspaceBackendConfiguration(array $backendConfig)
    {
        $backendType = isset($backendConfig['type']) ? $backendConfig['type'] : null;
        return new WorkspaceBackendConfig($backendType);
    }
}
