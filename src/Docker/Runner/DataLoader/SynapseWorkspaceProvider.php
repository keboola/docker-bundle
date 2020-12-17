<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

class SynapseWorkspaceProvider extends AbstractWorkspaceProvider
{
    protected function getType()
    {
        return 'synapse';
    }
}
