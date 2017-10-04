<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\StorageApi\Components;
use Keboola\Syrup\Exception\UserException;

class JobDefinitionParser
{
    /**
     * @var JobDefinition[]
     */
    private $jobDefinitions;

    /**
     * @var array
     */
    private $components;

    public function __construct(array $components)
    {
        $this->components = $components;
    }

    public function parseConfigData($componentId, array $configData)
    {
        $jobDefinition = new JobDefinition();
        $jobDefinition->setComponent($this->getComponent($componentId));
        $jobDefinition->setConfiguration($configData);
        $this->jobDefinitions[] = $jobDefinition;
    }

    public function parseConfig($componentId, array $config)
    {

    }

    public function getJobDefinitions()
    {
        return $this->jobDefinitions();
    }

    /**
     * @param $id
     * @return array
     */
    protected function getComponent($id)
    {
        foreach ($this->components as $c) {
            if ($c["id"] == $id) {
                $component = $c;
            }
        }
        if (!isset($component)) {
            throw new UserException("Component '{$id}' not found.");
        }
        return $component;
    }

}
