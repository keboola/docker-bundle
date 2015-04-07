<?php

namespace Keboola\DockerBundle\Monolog\Processor;

/**
 * Class DockerProcessor implements a simple log processor which changes component
 *  name of events.
 * @package Keboola\DockerBundle\Monolog
 */
class DockerProcessor
{
    /**
     * @param  array $record
     * @return array
     */
    public function __invoke(array $record)
    {
        return $this->processRecord($record);
    }


    /**
     * Constructor.
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
        $record['app'] = 'docker';
        return $record;
    }
}
