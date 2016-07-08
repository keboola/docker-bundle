<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Monolog\Processor\DockerContainerProcessor;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Keboola\Syrup\Monolog\Handler\StorageApiHandler as SyrupStorageApiHandler;
use Monolog\Logger;

class LoggersService
{
    private $logger;
    private $containerLogger;
    private $sapiHandler;

    public function __construct(Logger $log, ContainerLogger $containerLog, StorageApiHandler $sapiHandler)
    {
        $this->logger = $log;
        $this->containerLogger = $containerLog;
        $this->sapiHandler = $sapiHandler;

        // copy all processors to ContainerLogger
        foreach ($this->logger->getProcessors() as $processor) {
            $this->containerLogger->pushProcessor($processor);
        }

        // copy all handlers to ContainerLogger except storageHandler, which is overridden
        foreach ($this->logger->getHandlers() as $handler) {
            if (!is_a($handler, SyrupStorageApiHandler::class)) {
                $this->containerLogger->pushHandler($handler);
            }
        }
        $this->containerLogger->pushHandler($this->sapiHandler);
    }

    public function setComponentId($componentId)
    {
        // attach the processor to all handlers and channels
        $processor = new DockerProcessor($componentId);
        $this->logger->pushProcessor([$processor, 'processRecord']);

        // attach the processor to all handlers and channels
        $processor = new DockerContainerProcessor($componentId);
        $this->containerLogger->pushProcessor([$processor, 'processRecord']);
    }

    public function getLog()
    {
        return $this->logger;
    }

    public function getContainerLog()
    {
        return $this->containerLogger;
    }

    public function setVerbosity(array $verbosity)
    {
        $this->sapiHandler->setVerbosity($verbosity);
    }
}
