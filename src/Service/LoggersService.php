<?php

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Monolog\Processor\DockerContainerProcessor;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Monolog\Logger;

class LoggersService
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
     * @var StorageApiHandler
     */
    private $sapiHandler;

    public function __construct(Logger $log, ContainerLogger $containerLog, $sapiHandler)
    {
        $this->logger = $log;
        $this->containerLogger = $containerLog;
        if ($sapiHandler && $sapiHandler instanceof StorageApiHandler) {
            $this->sapiHandler = $sapiHandler;
        }

        // copy all processors to ContainerLogger
        foreach ($this->logger->getProcessors() as $processor) {
            $this->containerLogger->pushProcessor($processor);
        }

        // copy all handlers to ContainerLogger except storageHandler, which is overridden
        foreach ($this->logger->getHandlers() as $handler) {
            // set level of notice to none on runner level
            if (is_a($handler, StorageApiHandler::class)) {
                /** @var StorageApiHandler $handler */
                $handler->setVerbosity([Logger::NOTICE => StorageApiHandler::VERBOSITY_NONE]);
            } else {
                $this->containerLogger->pushHandler($handler);
            }
        }

        // Storage API handler for container is overridden, because it has different visibility
        // settings than the runner handler above.
        if ($this->sapiHandler) {
            $this->containerLogger->pushHandler($this->sapiHandler);
        }
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

    /**
     * @return Logger
     */
    public function getLog()
    {
        return $this->logger;
    }

    /**
     * @return ContainerLogger
     */
    public function getContainerLog()
    {
        return $this->containerLogger;
    }

    public function setVerbosity(array $verbosity)
    {
        if ($this->sapiHandler) {
            $this->sapiHandler->setVerbosity($verbosity);
        }
    }
}
