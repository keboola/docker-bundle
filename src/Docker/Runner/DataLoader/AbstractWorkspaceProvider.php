<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\ProviderInterface;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Workspaces;

abstract class AbstractWorkspaceProvider implements ProviderInterface
{
    /** @var Client */
    protected $client;

    /** @var array */
    protected $workspace;

    /** @var string */
    protected $componentId;

    /** @var string */
    protected $configurationId;

    public function __construct(Client $client, $componentId, $configurationId)
    {
        $this->client = $client;
        $this->componentId = $componentId;
        $this->configurationId = $configurationId;
        $this->workspace = null;
    }

    protected abstract function getType();

    protected function createWorkspace()
    {
        if ($this->configurationId) {
            $components = new Components($this->client);
            $this->workspace = $components->createConfigurationWorkspace(
                $this->componentId,
                $this->configurationId,
                ['backend' => $this->getType()]
            );
        } else {
            $workspaces = new Workspaces($this->client);
            $this->workspace = $workspaces->createWorkspace(['backend' => $this->getType()]);
        }
    }

    public function getWorkspaceId()
    {
        if (!$this->workspace) {
            $this->createWorkspace();
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

    public function getCredentials()
    {
        if (!$this->workspace) {
            $this->createWorkspace();
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

    public function getPath()
    {
        throw new ApplicationException('Workspace provides no path.');
    }
}
