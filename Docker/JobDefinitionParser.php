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
     * @param null $configId
     */
    public function parseConfigData(Component $component, array $configData, $configId = null)
    {
        $jobDefinition = new JobDefinition($configData, $component, $configId);
        $this->jobDefinitions = [$jobDefinition];
    }

    /**
     * @param Component $component
     * @param $config
     */
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
     * @param string|null $rowId
     * @return JobDefinition[]
     */
    public function getJobDefinitions()
    {
        return $this->jobDefinitions;
    }
}
