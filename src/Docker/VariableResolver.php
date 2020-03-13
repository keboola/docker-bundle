<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Configuration\Variables;
use Keboola\DockerBundle\Docker\Configuration\VariableValues;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Mustache_Engine;

class VariableResolver
{
    /**
     * @var Client
     */
    private $client;

    const KEBOOLA_VARIABLES = 'keboola.variables';
    /**
     * @var Mustache_Engine
     */
    private $moustache;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->moustache = new Mustache_Engine();
    }

    public function resolveVariables(array $jobDefinitions, $variableValuesId, $variableValuesData)
    {
        if ($variableValuesId && $variableValuesData) {
            throw new UserException('Only one of variableValuesData and variableValuesId can be entered');
        }
        /** @var JobDefinition $jobDefinition */
        $newJobDefinitions = [];
        foreach ($jobDefinitions as $jobDefinition) {
            if ($jobDefinition->getConfiguration()['variables_id']) {
                $components = new Components($this->client);
                $vConfiguration = $components->getConfiguration(self::KEBOOLA_VARIABLES, $jobDefinition->getConfigId());
                $vConfiguration = (new Variables())->parse(array('config' => $vConfiguration));
                if ($variableValuesData) {
                    $vRow = $variableValuesData;
                } elseif ($variableValuesId) {
                    $vRow = $components->getConfigurationRow(self::KEBOOLA_VARIABLES, $jobDefinition->getConfigId(), $jobDefinition->getConfiguration()['variables_values_id']);
                } elseif ($jobDefinition->getConfiguration()['variables_values_id']) {
                    $vRow = $components->getConfigurationRow(self::KEBOOLA_VARIABLES, $jobDefinition->getConfigId(), $jobDefinition->getConfiguration()['variables_values_id']);
                } else {
                    throw new UserException('No variables provided for configuration');
                }
                $vRow = (new VariableValues())->parse(array('config' => $vRow));
                $context = new VariablesContext($vRow);
                $newJobDefinitions[] = new JobDefinition(
                    json_decode($this->moustache->render(json_encode($jobDefinition->getConfiguration()), $context)),
                    $jobDefinition->getComponent(),
                    $jobDefinition->getConfigId(),
                    $jobDefinition->getConfigVersion(),
                    $jobDefinition->getState(),
                    $jobDefinition->getRowId(),
                    $jobDefinition->isDisabled()
                );
            }
        }
        return $newJobDefinitions;
    }
}
