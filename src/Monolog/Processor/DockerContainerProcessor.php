<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Monolog\Processor;

use Monolog\LogRecord;

/**
 * Class DockerProcessor implements a simple log processor which changes component
 *  name of events.
 * @package Keboola\DockerBundle\Monolog
 */
class DockerContainerProcessor
{
    public function __construct(
        private readonly string $componentName,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $this->processRecord($record);
    }

    public function processRecord(LogRecord $record): LogRecord
    {
        // todo change this to proper channel, when this is resolved https://github.com/keboola/docker-bundle/issues/64
        $record = $record->with('channel', 'docker');

        $record->extra['component'] = $this->componentName;
        return $record;
    }
}
