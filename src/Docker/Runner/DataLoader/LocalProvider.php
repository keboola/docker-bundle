<?php

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\InputMapping\Staging\ProviderInterface;

class LocalProvider implements ProviderInterface
{
    /** @var string */
    protected $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function getWorkspaceId()
    {
        throw new ApplicationException('Local provider has no workspace');
    }

    public function cleanup()
    {
    }

    public function getCredentials()
    {
        throw new ApplicationException('Local provider has no workspace');
    }

    public function getPath()
    {
        $this->path;
    }
}
