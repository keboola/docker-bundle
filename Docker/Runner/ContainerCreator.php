<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Monolog\Logger;

class ContainerCreator
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ContainerLogger
     */
    private $containerLogger;

    /**
     * @var string
     */
    private $dataDirectory;

    /**
     * @var array
     */
    private $environmentVariables;

    public function __construct(Logger $logger, ContainerLogger $containerLogger, $dataDirectory, $environmentVariables)
    {
        $this->logger = $logger;
        $this->containerLogger = $containerLogger;
        $this->dataDirectory = $dataDirectory;
        $this->environmentVariables = $environmentVariables;
    }

    public function createContainerFromImage(Image $image, $containerId)
    {
        return new Container(
            $containerId,
            $image,
            $this->logger,
            $this->containerLogger,
            $this->dataDirectory,
            $this->environmentVariables
        );
    }
}
