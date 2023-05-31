<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\UserException;

class JobDefinitionParser
{
    /**
     * @var JobDefinition[]
     */
    private $jobDefinitions = [];

    /**
     * @param Component $component
     * @param array $configData
     * @param string $configId
     */
    public function parseConfigData(Component $component, array $configData, $configId = null)
    {
        $jobDefinition = new JobDefinition($configData, $component, $configId);
        $this->jobDefinitions = [$jobDefinition];
    }

    /**
     * @param Component $component
     * @param array $config
     */
    public function parseConfig(Component $component, $config)
    {
        $config['rows'] = $config['rows'] ?? [];
        $this->validateConfig($config);
        $this->jobDefinitions = [];
        if (count($config['rows']) === 0) {
            $jobDefinition = new JobDefinition(
                $config['configuration'] ? (array) $config['configuration'] : [],
                $component,
                (string) $config['id'],
                (string) $config['version'],
                $config['state'] ? (array) $config['state'] : []
            );
            $this->jobDefinitions[] = $jobDefinition;
        } else {
            foreach ($config['rows'] as $row) {
                $jobDefinition = new JobDefinition(
                    array_replace_recursive($config['configuration'], $row['configuration']),
                    $component,
                    (string) $config['id'],
                    (string) $config['version'],
                    $row['state'] ? (array) $row['state'] : [],
                    (string) $row['id'],
                    (bool) $row['isDisabled']
                );
                $this->jobDefinitions[] = $jobDefinition;
            }
        }
    }

    private function validateConfig($config)
    {
        $hasProcessors = !empty($config['configuration']['processors']['before'])
            || !empty($config['configuration']['processors']['after']);
        $hasRowProcessors = $this->hasRowProcessors($config);
        if ($hasProcessors && $hasRowProcessors) {
            throw new UserException(
                'Processors may be set either in configuration or in configuration row, but not in both places.'
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
