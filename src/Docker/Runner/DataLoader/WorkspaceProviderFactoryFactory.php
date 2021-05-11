<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\StrategyFactory as InputStrategyFactory;
use Keboola\StagingProvider\Staging\Workspace\AbsWorkspaceStaging;
use Keboola\StagingProvider\WorkspaceProviderFactory\ExistingFilesystemWorkspaceProviderFactory;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StagingProvider\WorkspaceProviderFactory\ComponentWorkspaceProviderFactory;
use Psr\Log\LoggerInterface;

class WorkspaceProviderFactoryFactory
{
    private $logger;
    private $clientWrapper;

    public function __construct(LoggerInterface $logger, ClientWrapper $clientWrapper)
    {
        $this->logger = $logger;
        $this->clientWrapper = $clientWrapper;
    }

    public function getWorkspaceProviderFactory($stagingStorage, Component $component, $configId)
    {
        /* There can only be one workspace type (ensured in validateStagingSetting()) - so we're checking
            just input staging here (because if it is workspace, it must be the same as output mapping). */
        if ($configId && ($stagingStorage === InputStrategyFactory::WORKSPACE_ABS)) {
            // ABS workspaces are persistent, but only if configId is present
            $workspaceProviderFactory = $this->getWorkspaceFactoryForPersistentAbsWorkspace($component, $configId);
        } else {
            $workspaceProviderFactory = new ComponentWorkspaceProviderFactory(
                new Components($this->clientWrapper->getBasicClient()),
                new Workspaces($this->clientWrapper->getBasicClient()),
                $component->getId(),
                $configId
            );
            $this->logger->info('Created a new ephemeral workspace.');
        }
        return $workspaceProviderFactory;
    }

    private function getWorkspaceFactoryForPersistentAbsWorkspace(Component $component, $configId)
    {
        // ABS workspaces are persistent, but only if configId is present
        $componentsApi = new Components($this->clientWrapper->getBasicClient());
        $listOptions = (new ListConfigurationWorkspacesOptions())
            ->setComponentId($component->getId())
            ->setConfigurationId($configId);
        $workspaces = $componentsApi->listConfigurationWorkspaces($listOptions);
        if (count($workspaces) === 0) {
            $workspace = $componentsApi->createConfigurationWorkspace(
                $component->getId(),
                $configId,
                ['backend' => AbsWorkspaceStaging::getType()]
            );
            $workspaceId = $workspace['id'];
            $connectionString = $workspace['connection']['connectionString'];
            $this->logger->info(sprintf('Created a new persistent workspace "%s".', $workspaceId));
        } elseif (count($workspaces) === 1) {
            $workspaceId = $workspaces[0]['id'];
            $workspaceApi = new Workspaces($this->clientWrapper->getBasicClient());
            $connectionString = $workspaceApi->resetWorkspacePassword($workspaceId)['connectionString'];
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
            new Workspaces($this->clientWrapper->getBasicClient()),
            $workspaceId,
            $connectionString
        );
    }
}
