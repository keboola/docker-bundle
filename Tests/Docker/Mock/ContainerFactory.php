<?php

namespace Keboola\DockerBundle\Tests\Docker\Mock;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Service\LoggersService;

/**
 * Class MockContainer
 * @package Keboola\DockerBundle\Tests
 */
class ContainerFactory extends \Keboola\DockerBundle\Docker\ContainerFactory
{
    private $runMethod;

    public function getContainer(Image $image, $dataDir, LoggersService $logService)
    {
        $container = new Container($image, $logService->getLog(), $logService->getContainerLog(), $dataDir);
        $container->setRunMethod($this->runMethod);
        return $container;
    }

    /**
     * @param callable $runMethod
     * @return $this
     */
    public function setRunMethod($runMethod)
    {
        $this->runMethod = $runMethod;
    }
}
