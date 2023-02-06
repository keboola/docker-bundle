<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Monolog\Processor;

/**
 * Class DockerProcessor implements a simple log processor which changes component
 *  name of events.
 * @package Keboola\DockerBundle\Monolog
 */
class DockerProcessor
{
    private $componentName;

    /**
     * @param  array $record
     * @return array
     */
    public function __invoke(array $record)
    {
        return $this->processRecord($record);
    }


    /**
     *
     * @param $componentName string Component name.
     */
    public function __construct($componentName)
    {
        $this->componentName = $componentName;
    }


    /**
     * Process event record.
     *
     * @param array $record Log Event.
     * @return array Log event.
     */
    public function processRecord(array $record)
    {
        $record['component'] = $this->componentName;
        // todo change this to proper channel, when this is resolved https://github.com/keboola/docker-bundle/issues/64
        $record['app'] = 'docker';
        return $record;
    }
}
