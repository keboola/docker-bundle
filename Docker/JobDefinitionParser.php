<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\Syrup\Exception\UserException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class JobDefinitionParser
{
    /**
     * @var JobDefinition[]
     */
    private $jobDefinitions = [];


    private function normalizeConfig($configData)
    {
        try {
            $configData = (new Configuration\Container())->parse(['container' => $configData]);
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), $e);
        }
        $configData['storage'] = empty($configData['storage']) ? [] : $configData['storage'];
        $configData['processors'] = empty($configData['processors']) ? [] : $configData['processors'];
        $configData['parameters'] = empty($configData['parameters']) ? [] : $configData['parameters'];

        return $configData;
    }

    /**
     * @param Component $component
     * @param array $configData
     */
    public function parseConfigData(Component $component, array $configData)
    {
        $jobDefinition = new JobDefinition();
        $jobDefinition->setComponent($component);
        $jobDefinition->setConfiguration($this->normalizeConfig($configData));
        $this->jobDefinitions[] = $jobDefinition;
    }

    public function parseConfig(Component $component, $config)
    {
        if (!$config['rows']) {
            $jobDefinition = new JobDefinition();
            $jobDefinition->setComponent($component);
            $jobDefinition->setConfiguration($this->normalizeConfig($config['configuration']));
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
