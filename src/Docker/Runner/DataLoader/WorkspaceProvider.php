<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Exception\UserException;
use Keboola\InputMapping\Reader\WorkspaceProviderInterface;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Workspaces;

class WorkspaceProvider implements WorkspaceProviderInterface
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var array
     */
    private $workspace;

    /**
     * @var string
     */
    private $componentId;

    /**
     * @var string
     */
    private $configurationId;

    public function __construct(Client $client, $componentId, $configurationId)
    {
        $this->client = $client;
        $this->componentId = $componentId;
        $this->configurationId = $configurationId;
        $this->workspace = null;
    }

    private function createWorkspace($type)
    {
        // this check is a workaround for https://keboola.atlassian.net/browse/KBC-236
        $workspaceTypes = [
            WorkspaceProviderInterface::TYPE_REDSHIFT,
            WorkspaceProviderInterface::TYPE_SNOWFLAKE,
            WorkspaceProviderInterface::TYPE_SYNAPSE,
        ];
        if (!in_array($type, $workspaceTypes)) {
            throw new UserException('Workspace type must be one of ' . implode(', ', $workspaceTypes));
        }
        if ($this->configurationId) {
            $components = new Components($this->client);
            $this->workspace = $components->createConfigurationWorkspace(
                $this->componentId,
                $this->configurationId,
                ['backend' => $type]
            );
        } else {
            $workspaces = new Workspaces($this->client);
            $this->workspace = $workspaces->createWorkspace(['backend' => $type]);
        }
    }

    public function getWorkspaceId($type)
    {
        if (!$this->workspace) {
            $this->createWorkspace($type);
        }
        if ($this->workspace['connection']['backend'] !== $type) {
            throw new UserException('Multiple workspaces are not supported');
        }
        return $this->workspace['id'];
    }

    public function cleanup()
    {
        if ($this->workspace) {
            $workspaces = new Workspaces($this->client);
            $workspaces->deleteWorkspace($this->workspace['id'], ['async' => true]);
        }
    }

    public function getCredentials($type)
    {
        if (!$this->workspace) {
            $this->createWorkspace($type);
        }
        if ($this->workspace['connection']['backend'] !== $type) {
            throw new UserException('Multiple workspaces are not supported');
        }
        return [
            'host' => $this->workspace['connection']['host'],
            'warehouse' => $this->workspace['connection']['warehouse'],
            'database' => $this->workspace['connection']['database'],
            'schema' => $this->workspace['connection']['schema'],
            'user' => $this->workspace['connection']['user'],
            'password' => $this->workspace['connection']['password'],
        ];
    }
}
