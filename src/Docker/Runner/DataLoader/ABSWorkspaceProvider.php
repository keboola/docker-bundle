<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

class ABSWorkspaceProvider extends AbstractWorkspaceProvider
{
    protected function getType()
    {
        return 'abs';
    }

    public function getCredentials()
    {
        if (!$this->workspace) {
            $this->createWorkspace();
        }
        return [
            'connectionString' => $this->workspace['connection']['connectionString'],
            'container' => $this->workspace['connection']['container'],
        ];
    }
}
