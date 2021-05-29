<?php

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\VariableResolver;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\Runner\CreateBranchTrait;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration as StorageConfiguration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;

class VariableResolverTest extends TestCase
{
    use CreateBranchTrait;

    /**
     * @var ClientWrapper
     */
    private $clientWrapper;

    /**
     * @var Component
     */
    private $component;

    private function createVariablesConfiguration(Client $client, array $data, array $rowData)
    {
        $components = new Components($client);
        $configuration = new StorageConfiguration();
        $configuration->setComponentId('keboola.variables');
        $configuration->setName('runner-test');
        $configuration->setConfiguration($data);
        $configId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($configId);
        $row = new ConfigurationRow($configuration);
        $row->setName('runner-test');
        $row->setConfiguration($rowData);
        $rowId = $components->addConfigurationRow($row)['id'];
        return [$configId, $rowId];
    }

    public function setUp()
    {
        parent::setUp();

        $this->clientWrapper = new ClientWrapper(
            new Client([
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]),
            null,
            new NullLogger(),
            ClientWrapper::BRANCH_MAIN
        );
        $components = new Components($this->clientWrapper->getBasicClient());
        $listOptions = new ListComponentConfigurationsOptions();
        $listOptions->setComponentId('keboola.variables');
        $configurations = $components->listComponentConfigurations($listOptions);
        foreach ($configurations as $configuration) {
            $components->deleteConfiguration('keboola.variables', $configuration['id']);
        }
        $this->component = new Component(
            [
                'id' => 'keboola.dummy',
                'data' => [
                    'definition' => [
                        'uri' => 'dummy',
                        'type' => 'aws-ecr',
                    ],
                ],
            ]
        );
    }

    public function testResolveVariablesValuesId()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}.'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables([$jobDefinition], $vRowId, [])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is bar.',
                ],
                'variables_id' => $vConfigurationId,
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue($logger->hasInfoThatContains('Replacing variables using values with ID:'));
        self::assertTrue($logger->hasInfoThatContains('Replaced values for variables: "foo".'));
    }

    public function testResolveVariablesValuesData()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables(
            [$jobDefinition],
            null,
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        )[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is bar',
                ],
                'variables_id' => $vConfigurationId,
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue($logger->hasInfoThatContains('Replacing variables using inline values.'));
        self::assertTrue($logger->hasInfoThatContains('Replaced values for variables: "foo".'));
    }

    public function testResolveVariablesDefaultValues()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'variables_values_id' => $vRowId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables([$jobDefinition], null, null)[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is bar',
                ],
                'variables_id' => $vConfigurationId,
                'variables_values_id' => $vRowId,
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue($logger->hasInfoThatContains('Replacing variables using default values with ID:'));
        self::assertTrue($logger->hasInfoThatContains('Replaced values for variables: "foo".'));
    }

    public function testResolveVariablesDefaultValuesOverride()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'variables_values_id' => 'not-used',
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables([$jobDefinition], $vRowId, null)[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is bar',
                ],
                'variables_id' => $vConfigurationId,
                'variables_values_id' => 'not-used',
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue($logger->hasInfoThatContains('Replacing variables using values with ID:'));
        self::assertTrue($logger->hasInfoThatContains('Replaced values for variables: "foo".'));
    }

    public function testResolveVariablesDefaultValuesOverrideData()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'variables_values_id' => $vRowId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables(
            [$jobDefinition],
            null,
            ['values' => [['name' => 'foo', 'value' => 'bazooka']]]
        )[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is bazooka',
                ],
                'variables_id' => $vConfigurationId,
                'variables_values_id' => $vRowId,
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue($logger->hasInfoThatContains('Replacing variables using inline values.'));
        self::assertTrue($logger->hasInfoThatContains('Replaced values for variables: "foo".'));
    }

    public function testResolveVariablesNoValues()
    {
        list ($vConfigurationId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '321', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'No variable values provided for configuration "123", row "321", referencing variables "' .
            $vConfigurationId . '".'
        );
        $variableResolver->resolveVariables([$jobDefinition], null, null)[0];
    }

    public function testResolveVariablesInvalidDefaultValues()
    {
        list ($vConfigurationId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'variables_values_id' => 'non-existent',
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '321', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Cannot read default variable values "non-existent" for configuration "123", row "321".'
        );
        $variableResolver->resolveVariables([$jobDefinition], null, null)[0];
    }

    public function testResolveVariablesInvalidProvidedValues()
    {
        list ($vConfigurationId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '321', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Cannot read requested variable values "non-existent" for configuration "123", row "321".'
        );
        $variableResolver->resolveVariables([$jobDefinition], 'non-existent', null)[0];
    }

    public function testResolveVariablesInvalidProvidedArguments()
    {
        list ($vConfigurationId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '321', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Only one of variableValuesId and variableValuesData can be entered.'
        );
        $variableResolver->resolveVariables(
            [$jobDefinition],
            'non-existent',
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        )[0];
    }

    public function testResolveVariablesNonExistentVariableConfiguration()
    {
        $configuration = [
            'variables_id' => 'non-existent',
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '321', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Variable configuration cannot be read: Configuration non-existent not found'
        );
        $variableResolver->resolveVariables([$jobDefinition], 'non-existent', null)[0];
    }

    public function testResolveVariablesInvalidVariableConfiguration()
    {
        list ($vConfigurationId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['invalid' => 'data'],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '321', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Variable configuration is invalid: Unrecognized option "invalid" under "configuration"'
        );
        $variableResolver->resolveVariables([$jobDefinition], 'non-existent', null)[0];
    }

    public function testResolveVariablesNoVariables()
    {
        $component = new Component(
            [
                'id' => 'keboola.dummy',
                'data' => [
                    'definition' => [
                        'uri' => 'dummy',
                        'type' => 'aws-ecr',
                    ],
                ],
            ]
        );
        $configuration = [
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables([$jobDefinition], '123', [])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is {{ foo }}',
                ],
                'storage' => [],
                'processors' => [],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertFalse($logger->hasInfoThatContains('Replacing variables using default values with ID:'));
    }

    public function testInvalidValuesConfiguration()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            ['invalid' => [['name' => 'foo', 'value' => 'bar']]]
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }} and {{ notreplaced }}.'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        self::expectException(UserException::class);
        self::expectExceptionMessage('Variable values configuration is invalid: Unrecognized option "invalid" under "configuration"');
        $variableResolver->resolveVariables([$jobDefinition], $vRowId, [])[0];
    }

    public function testResolveVariablesSpecialCharacterReplacement()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables(
            [$jobDefinition],
            null,
            ['values' => [['name' => 'foo', 'value' => 'special " \' { } characters']]]
        )[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is special " \' { } characters',
                ],
                'variables_id' => $vConfigurationId,
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue($logger->hasInfoThatContains('Replacing variables using inline values.'));
        self::assertTrue($logger->hasInfoThatContains('Replaced values for variables: "foo".'));
    }

    public function testResolveVariablesSpecialCharacterNonEscapedReplacement()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{{ foo }}}'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage('Variable replacement resulted in invalid configuration, error: Syntax error');
        $variableResolver->resolveVariables(
            [$jobDefinition],
            null,
            ['values' => [['name' => 'foo', 'value' => 'special " \' { } characters']]]
        )[0];
    }

    public function testResolveVariablesMissingValues()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string'], ['name' => 'goo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}.'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage('No value provided for variable "goo".');
        $variableResolver->resolveVariables(
            [$jobDefinition],
            null,
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        );
    }

    public function testResolveVariablesMissingValuesInBody()
    {
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $this->clientWrapper->getBasicClient(),
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            []
        );
        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }} and bar is {{ bar }} and baz is {{ baz }}.'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage('Missing values for placeholders: "bar, baz"');
        $variableResolver->resolveVariables(
            [$jobDefinition],
            null,
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        );
    }

    public function testResolveVariablesValuesBranch()
    {
        $client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN_MASTER,
        ]);
        list ($vConfigurationId, $vRowId) = $this->createVariablesConfiguration(
            $client,
            ['variables' => [['name' => 'foo', 'type' => 'string']]],
            ['values' => [['name' => 'foo', 'value' => 'bar']]]
        );
        $branchId = $this->createBranch($client, 'my-dev-branch');
        $this->clientWrapper = new ClientWrapper(
            $client,
            null,
            new NullLogger(),
            $branchId
        );

        // modify the dev branch variable configuration to "dev-bar"
        $components = new Components($this->clientWrapper->getBranchClient());
        $configuration = new StorageConfiguration();
        $configuration->setComponentId('keboola.variables');
        $configuration->setConfigurationId($vConfigurationId);
        $newRow = new ConfigurationRow($configuration);
        $newRow->setRowId($vRowId);
        $newRow->setConfiguration(['values' => [['name' => 'foo', 'value' => 'dev-bar']]]);
        $components->updateConfigurationRow($newRow);

        $configuration = [
            'variables_id' => $vConfigurationId,
            'parameters' => ['some_parameter' => 'foo is {{ foo }}.'],
        ];
        $logger = new TestLogger();
        $variableResolver = new VariableResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $variableResolver->resolveVariables([$jobDefinition], $vRowId, [])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' => 'foo is dev-bar.',
                ],
                'variables_id' => $vConfigurationId,
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'shared_code_row_ids' => [],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue($logger->hasInfoThatContains('Replacing variables using values with ID:'));
        self::assertTrue($logger->hasInfoThatContains('Replaced values for variables: "foo".'));
    }
}
