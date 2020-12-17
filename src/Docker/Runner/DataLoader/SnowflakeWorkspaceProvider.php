<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

class SnowflakeWorkspaceProvider extends AbstractWorkspaceProvider
{
    protected function getType()
    {
        return 'snowflake';
    }
}
