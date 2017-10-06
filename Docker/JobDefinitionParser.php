<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\Syrup\Exception\UserException;

class JobDefinitionParser
{
    /**
     * @var JobDefinition[]
     */
    private $jobDefinitions = [];

    /**
     * @param Component $component
     * @param array $configData
     */
    public function parseConfigData(Component $component, array $configData)
    {
        $jobDefinition = new JobDefinition($configData);
        $jobDefinition->setComponent($component);
        $this->jobDefinitions = [$jobDefinition];
    }

    public function parseConfig(Component $component, $config)
    {
        $this->jobDefinitions = [];
        if (count($config['rows']) == 0) {
            $jobDefinition = new JobDefinition($config['configuration']);
            $jobDefinition->setComponent($component);
            $jobDefinition->setConfigId($config['id']);
            $jobDefinition->setConfigVersion($config['version']);
            $jobDefinition->setState($config['state']);
            $this->jobDefinitions[] = $jobDefinition;
        } else {
            return;
        }
    }

    /**
     * @return JobDefinition[]
     */
    public function getJobDefinitions()
    {
        return $this->jobDefinitions;
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
        return new Component($component);
    }
}
