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
        $this->validateConfig($component, $config);
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

    private function validateConfig(Component $component, $config)
    {
        $hasProcessors = !empty($config['configuration']['processors']['before'])
            || !empty($config['configuration']['processors']['after']);
        $hasRowProcessors = $this->hasRowProcessors($config);
        if ($component->getStagingStorage()['input'] !== 'local' && ($hasRowProcessors || $hasProcessors)) {
            throw new UserException(
                "Processors cannot be used with component " . $component->getId() .
                ' because it does not use local staging storage.'
            );
        }
        if ($hasProcessors && $hasRowProcessors) {
            throw new UserException(
                "Processors may be set either in configuration or in configuration row, but not in both places."
            );
        }
    }

    private function hasRowProcessors($config)
    {
        foreach ($config['rows'] as $row) {
            if (!empty($row['configuration']['processors']['before'])
                || !empty($row['configuration']['processors']['after'])
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return JobDefinition[]
     */
    public function getJobDefinitions()
    {
        return $this->jobDefinitions;
    }
}
