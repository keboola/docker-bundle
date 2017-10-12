<?php

namespace Keboola\DockerBundle\Docker;

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
        $jobDefinition = new JobDefinition($configData, $component);
        $this->jobDefinitions = [$jobDefinition];
    }

    public function parseConfig(Component $component, $config)
    {
        $this->jobDefinitions = [];
        if (count($config['rows']) == 0) {
            $jobDefinition = new JobDefinition($config['configuration'], $component, $config['id'], $config['version'], $config['state']);
            $this->jobDefinitions[] = $jobDefinition;
        } else {
            foreach ($config['rows'] as $row) {
                $jobDefinition = new JobDefinition(
                    array_replace_recursive($config['configuration'], $row['configuration']),
                    $component,
                    $config['id'],
                    $config['version'],
                    $row['state'],
                    $row['id'],
                    $row['isDisabled']
                );
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
