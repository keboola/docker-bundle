<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Service;

use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandlerInterface;
use Keboola\DockerBundle\Monolog\Processor\DockerContainerProcessor;
use Keboola\DockerBundle\Monolog\Processor\DockerProcessor;
use Monolog\Logger;

class LoggersService
{
    private Logger $logger;
    private ContainerLogger $containerLogger;
    private ?StorageApiHandlerInterface $sapiHandler;

    public function __construct(Logger $log, ContainerLogger $containerLog, ?StorageApiHandlerInterface $sapiHandler)
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
            // set level of notice to none on runner level
            if ($handler instanceof StorageApiHandlerInterface) {
                /** @var StorageApiHandlerInterface $handler */
                $handler->setVerbosity([Logger::NOTICE => StorageApiHandlerInterface::VERBOSITY_NONE]);
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

    public function setComponentId(string $componentId): void
    {
        // attach the processor to all handlers and channels
        $processor = new DockerProcessor($componentId);
        $this->logger->pushProcessor([$processor, 'processRecord']);

        // attach the processor to all handlers and channels
        $processor = new DockerContainerProcessor($componentId);
        $this->containerLogger->pushProcessor([$processor, 'processRecord']);
    }

    public function getLog(): Logger
    {
        return $this->logger;
    }

    public function getContainerLog(): ContainerLogger
    {
        return $this->containerLogger;
    }

    public function setVerbosity(array $verbosity): void
    {
        $this->sapiHandler?->setVerbosity($verbosity);
    }
}
