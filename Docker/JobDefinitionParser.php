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
            foreach ($config['rows'] as $row) {
                $jobDefinition = new JobDefinition(array_replace_recursive($config['configuration'], $row['configuration']));
                $jobDefinition->setComponent($component);
                $jobDefinition->setConfigId($config['id']);
                $jobDefinition->setConfigVersion($config['version']);
                $jobDefinition->setRowId($row['id']);
                $jobDefinition->setRowVersion($row['version']);
                $jobDefinition->setState($row['state']);
                $jobDefinition->setIsDisabled($row['isDisabled']);
                $this->jobDefinitions[] = $jobDefinition;
            }
        }
    }

    /**
     * @return JobDefinition[]
     */
    public function getJobDefinitions()
    {
        return $this->jobDefinitions;
    }
}
