<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Keboola\Syrup\Monolog\Handler\StorageApiHandler;
use Monolog\Logger;

class LoggersService
{
    private $logger;
    private $containerLogger;

    public function __construct(Logger $log, ContainerLogger $containerLog, StorageApiHandler $sapiHandler)
    {
        $this->logger = $log;
        $this->containerLogger = $containerLog;

        // copy all processors to ContainerLogger
        foreach ($this->logger->getProcessors() as $processor) {
            $this->containerLogger->pushProcessor($processor);
        }

        // copy all handlers to ContainerLogger except storageHandler, which is overridden
        foreach ($this->logger->getHandlers() as $handler) {
            if (!is_a($handler, StorageApiHandler::class)) {
                $this->containerLogger->pushHandler($handler);
            }
        }
        $this->containerLogger->pushHandler($sapiHandler);

    }

    public function setComponentId($componentId)
    {
        $processor = new DockerProcessor($componentId);
        // attach the processor to all handlers and channels
        $this->logger->pushProcessor([$processor, 'processRecord']);
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
}
