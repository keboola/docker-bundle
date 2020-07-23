<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration as StorageConfiguration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

class SharedCodeResolverTest extends TestCase
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var Component
     */
    private $component;

    private function createSharedCodeConfiguration(Client $client, array $rowDatas)
    {
        $components = new Components($client);
        $configuration = new StorageConfiguration();
        $configuration->setComponentId('keboola.shared-code');
        $configuration->setName('runner-test');
        $configuration->setConfiguration(['componentId' => 'keboola.snowflake-transformation']);
        $configId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($configId);
        $rowIds = [];
        foreach ($rowDatas as $index => $rowData) {
            $row = new ConfigurationRow($configuration);
            $row->setName('runner-test');
            $row->setRowId($index);
            $row->setConfiguration($rowData);
            $rowIds[] = $components->addConfigurationRow($row)['id'];
        }
        return [$configId, $rowIds];
    }

    public function setUp()
    {
        parent::setUp();

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $components = new Components($this->client);
        $listOptions = new ListComponentConfigurationsOptions();
        $listOptions->setComponentId('keboola.shared-code');
        $configurations = $components->listComponentConfigurations($listOptions);
        foreach ($configurations as $configuration) {
            $components->deleteConfiguration('keboola.shared-code', $configuration['id']);
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

    public function testResolveSharedCode()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->client,
            [
                'first_code' => ['code_content' => 'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id'],
                'secondCode' => ['code_content' => 'bar']
            ]
        );
        $configuration = [
            'shared_code_id' => $sharedConfigurationId,
            'shared_code_row_ids' => $sharedCodeRowIds,
            'parameters' => [
                'some_parameter' => 'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .'
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($this->client, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' =>
                        'foo is {{ foo }} and {{ non-existent }} and ' .
                        'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id and bar .',
                ],
                'storage' => [],
                'shared_code_id' => $sharedConfigurationId,
                'shared_code_row_ids' => [0 => 'first_code', 1 => 'secondCode'],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertTrue(
            $logger->hasInfoThatContains('Loaded shared code snippets with ids: "first_code, secondCode".')
        );
    }

    public function testResolveSharedCodeNoConfiguration()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->client,
            [
                'first_code' => ['code_content' => 'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id'],
                'secondCode' => ['code_content' => 'bar']
            ]
        );
        $configuration = [
            'shared_code_row_ids' => $sharedCodeRowIds,
            'parameters' => [
                'some_parameter' => 'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .'
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($this->client, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' =>
                        'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .',
                ],
                'storage' => [],
                'shared_code_row_ids' => [0 => 'first_code', 1 => 'secondCode'],
                'processors' => [
                ],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertFalse(
            $logger->hasInfoThatContains('Loaded shared code snippets with ids: "first_code, secondCode".')
            );
    }

    public function testResolveSharedCodeNoRows()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->client,
            [
                'first_code' => ['code_content' => 'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id'],
                'secondCode' => ['code_content' => 'bar']
            ]
        );
        $configuration = [
            'shared_code_id' => $sharedConfigurationId,
            'parameters' => [
                'some_parameter' => 'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .'
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($this->client, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        $newJobDefinition = $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' =>
                        'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .',
                ],
                'storage' => [],
                'shared_code_id' => $sharedConfigurationId,
                'shared_code_row_ids' => [],
                'processors' => [
                ],
            ],
            $newJobDefinition->getConfiguration()
        );
        self::assertFalse(
            $logger->hasInfoThatContains('Loaded shared code snippets with ids: "first_code, secondCode".')
        );
    }

    public function testResolveSharedCodeNonExistentConfiguration()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->client,
            [
                'first_code' => ['code_content' => 'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id'],
                'secondCode' => ['code_content' => 'bar']
            ]
        );
        $configuration = [
            'shared_code_id' => 'non-existent',
            'shared_code_row_ids' => $sharedCodeRowIds,
            'parameters' => [
                'some_parameter' => 'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .'
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($this->client, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Shared code configuration cannot be read: Configuration non-existent not found'
        );
        $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
    }

    public function testResolveSharedCodeNonExistentRow()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->client,
            [
                'first_code' => ['code_content' => 'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id'],
                'secondCode' => ['code_content' => 'bar']
            ]
        );
        $configuration = [
            'shared_code_id' => $sharedConfigurationId,
            'shared_code_row_ids' => ['foo', 'bar'],
            'parameters' => [
                'some_parameter' => 'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .'
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($this->client, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Shared code configuration cannot be read: Row foo not found'
        );
        $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
    }

    public function testResolveSharedCodeInvalidRow()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->client,
            [
                'first_code' => ['this is broken' => 'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id'],
                'secondCode' => ['code_content' => 'bar']
            ]
        );
        $configuration = [
            'shared_code_id' => $sharedConfigurationId,
            'shared_code_row_ids' => $sharedCodeRowIds,
            'parameters' => [
                'some_parameter' => 'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .'
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($this->client, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        /** @var JobDefinition $newJobDefinition */
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Shared code configuration is invalid: Unrecognized option "this is broken" under "configuration"'
        );
        $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
    }
}
