<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Configuration\Variables;
use Keboola\DockerBundle\Docker\Configuration\VariableValues;
use Keboola\DockerBundle\Docker\Runner\VariablesContext;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Mustache_Engine;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

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

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Client $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->moustache = new Mustache_Engine();
        $this->logger = $logger;
    }

    public function resolveVariables(array $jobDefinitions, $variableValuesId, $variableValuesData)
    {
        if ($variableValuesId && $variableValuesData) {
            throw new UserException('Only one of variables_id and variableValuesId can be entered');
        }
        /** @var JobDefinition $jobDefinition */
        $newJobDefinitions = [];
        foreach ($jobDefinitions as $jobDefinition) {
            $variablesId = $jobDefinition->getConfiguration()['variables_id'];
            $defaultValuesId = $jobDefinition->getConfiguration()['variables_values_id'];
            if ($variablesId) {
                $components = new Components($this->client);
                try {
                    $vConfiguration = $components->getConfiguration(self::KEBOOLA_VARIABLES, $variablesId);
                    $vConfiguration = (new Variables())->parse(array('config' => $vConfiguration['configuration']));
                } catch (ClientException $e) {
                    throw new UserException('Variable configuration cannot be read: ' . $e->getMessage(), $e);
                } catch (InvalidConfigurationException $e) {
                    throw new UserException('Variable configuration is invalid: ' . $e->getMessage(), $e);
                }
                if ($variableValuesData) {
                    $this->logger->info('Replacing variables using inline values.');
                    $vRow = $variableValuesData;
                } elseif ($variableValuesId) {
                    $this->logger->info(sprintf('Replacing variables using values with ID: "%s".', $variableValuesId));
                    try {
                        $vRow = $components->getConfigurationRow(
                            self::KEBOOLA_VARIABLES,
                            $variablesId,
                            $variableValuesId
                        );
                        $vRow = $vRow['configuration'];
                    } catch (ClientException $e) {
                        throw new UserException(
                            sprintf(
                                'Cannot read requested variable values "%s" for configuration "%s", row "%s".',
                                $variableValuesId,
                                $jobDefinition->getConfigId(),
                                $jobDefinition->getRowId()
                            ),
                            $e
                        );
                    }
                } elseif ($defaultValuesId) {
                    $this->logger->info(
                        sprintf('Replacing variables using default values with ID: "%s"', $defaultValuesId)
                    );
                    try {
                        $vRow = $components->getConfigurationRow(
                            self::KEBOOLA_VARIABLES,
                            $variablesId,
                            $defaultValuesId
                        );
                        $vRow = $vRow['configuration'];
                    } catch (ClientException $e) {
                        throw new UserException(
                            sprintf(
                                'Cannot read default variable values "%s" for configuration "%s", row "%s".',
                                $defaultValuesId,
                                $jobDefinition->getConfigId(),
                                $jobDefinition->getRowId()
                            ),
                            $e
                        );
                    }
                } else {
                    throw new UserException(sprintf(
                        'No variable values provided for configuration "%s", row "%s", referencing variables "%s".',
                        $jobDefinition->getConfigId(),
                        $jobDefinition->getRowId(),
                        $variablesId
                    ));
                }
                $vRow = (new VariableValues())->parse(array('config' => $vRow));
                $context = new VariablesContext($vRow);
                $variableNames = [];
                foreach ($vConfiguration['variables'] as $variable) {
                    $variableNames[] = $variable['name'];
                    if (!isset($context[$variable['name']])) {
                        throw new UserException(sprintf('No value provided for variable "%s".', $variable['name']));
                    }
                }
                $this->logger->info(sprintf('Replaced values for variables: "%s".', implode(', ', $variableNames)));

                $newJobDefinitions[] = new JobDefinition(
                    json_decode(
                        $this->moustache->render(json_encode($jobDefinition->getConfiguration()), $context),
                        true
                    ),
                    $jobDefinition->getComponent(),
                    $jobDefinition->getConfigId(),
                    $jobDefinition->getConfigVersion(),
                    $jobDefinition->getState(),
                    $jobDefinition->getRowId(),
                    $jobDefinition->isDisabled()
                );
            } else {
                $newJobDefinitions[] = $jobDefinition;
            }
        }
        return $newJobDefinitions;
    }
}
