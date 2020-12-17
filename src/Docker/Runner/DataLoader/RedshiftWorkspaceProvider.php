<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

class RedshiftWorkspaceProvider extends AbstractWorkspaceProvider
{
    protected function getType()
    {
        return 'redshift';
    }
}
