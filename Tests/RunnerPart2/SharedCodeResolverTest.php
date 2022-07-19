<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\Runner\CreateBranchTrait;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration as StorageConfiguration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

class SharedCodeResolverTest extends TestCase
{
    use CreateBranchTrait;

    private ClientWrapper $clientWrapper;
    private Component $component;

    private function createSharedCodeConfiguration(Client $client, array $rowDatas): array
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

    public function setUp(): void
    {
        parent::setUp();

        $this->clientWrapper = new ClientWrapper(
            new ClientOptions(
                STORAGE_API_URL,
                STORAGE_API_TOKEN,
            )
        );
        $components = new Components($this->clientWrapper->getBasicClient());
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

    public function testResolveSharedCode(): void
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->clientWrapper->getBasicClient(),
            [
                'first_code' => ['code_content' => ['SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id']],
                // bwd compatible shared code configuration where the code is not array
                'secondCode' => ['code_content' => 'bar'],
            ]
        );
        $configuration = [
            'shared_code_id' => $sharedConfigurationId,
            'shared_code_row_ids' => $sharedCodeRowIds,
            'parameters' => [
                'simple_code' => ['{{secondCode}}'],
                'multiple_codes' => ['{{ first_code }}', 'and {{ secondCode }}.'],
                'non_replaced_code' => '{{secondCode}}',
                'child' => [
                    'also_non_replaced' => '{{secondCode}}',
                ],
                'mixed array' => [
                    '{{secondCode}}',
                    'keyed' => '{{secondCode}}',
                    '{{first_code}}',
                ],
                'Some Inline Variable' => ['some text {{some_var}} some text {{some_other_var}}'],
                'Variables With Code' => ['some text {{some_var}} some text {{some_other_var}} and {{first_code}}'],
                'Variables With 2 Codes' => [
                    'some text {{some_var}} some text {{some_other_var}} and {{first_code}} and {{secondCode}}'
                ],
                'Variables With 3 Codes' => [
                    'some text {{some_var}} some text {{some_other_var}} and {{first_code}} and {{secondCode}}',
                    '{{first_code}}'
                ],
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        $newJobDefinition = $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'simple_code' => ['bar'],
                    'multiple_codes' => [
                        'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id',
                        'bar',
                    ],
                    'non_replaced_code' => '{{secondCode}}',
                    'child' => [
                        'also_non_replaced' => '{{secondCode}}',
                    ],
                    'mixed array' => [
                        '{{secondCode}}',
                        'keyed' => '{{secondCode}}',
                        '{{first_code}}',
                    ],
                    'Some Inline Variable' => [
                        'some text {{some_var}} some text {{some_other_var}}',
                    ],
                    'Variables With Code' => [
                        'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id',
                    ],
                    'Variables With 2 Codes' => [
                        'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id',
                        'bar',
                    ],
                    'Variables With 3 Codes' => [
                        'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id',
                        'bar',
                        'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id',
                    ],
                ],
                'storage' => [],
                'shared_code_id' => $sharedConfigurationId,
                'shared_code_row_ids' => ['first_code', 'secondCode'],
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
            $this->clientWrapper->getBasicClient(),
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
        $sharedCodeResolver = new SharedCodeResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        $newJobDefinition = $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' =>
                        'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .',
                ],
                'storage' => [],
                'shared_code_row_ids' => ['first_code', 'secondCode'],
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
            $this->clientWrapper->getBasicClient(),
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
        $sharedCodeResolver = new SharedCodeResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
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
            $this->clientWrapper->getBasicClient(),
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
        $sharedCodeResolver = new SharedCodeResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Shared code configuration cannot be read: Configuration non-existent not found'
        );
        $sharedCodeResolver->resolveSharedCode([$jobDefinition]);
    }

    public function testResolveSharedCodeNonExistentRow()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->clientWrapper->getBasicClient(),
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
        $sharedCodeResolver = new SharedCodeResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Shared code configuration cannot be read: Row foo not found'
        );
        $sharedCodeResolver->resolveSharedCode([$jobDefinition]);
    }

    public function testResolveSharedCodeInvalidRow()
    {
        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->clientWrapper->getBasicClient(),
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
        $sharedCodeResolver = new SharedCodeResolver($this->clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Shared code configuration is invalid: Unrecognized option "this is broken" under "configuration"'
        );
        $sharedCodeResolver->resolveSharedCode([$jobDefinition]);
    }

    public function testResolveSharedCodeBranch()
    {
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                STORAGE_API_URL,
                STORAGE_API_TOKEN_MASTER
            )
        );

        list ($sharedConfigurationId, $sharedCodeRowIds) = $this->createSharedCodeConfiguration(
            $this->clientWrapper->getBasicClient(),
            [
                'first_code' => ['code_content' => ['SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id']],
                'secondCode' => ['code_content' => ['bar']]
            ]
        );
        $branchId = $this->createBranch($clientWrapper->getBasicClient(), 'my-dev-branch');
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                STORAGE_API_URL,
                STORAGE_API_TOKEN_MASTER,
                $branchId
            )
        );

        // modify the dev branch shared code configuration to "dev-bar"
        $components = new Components($clientWrapper->getBranchClient());
        $configuration = new StorageConfiguration();
        $configuration->setComponentId('keboola.shared-code');
        $configuration->setConfigurationId($sharedConfigurationId);
        $newRow = new ConfigurationRow($configuration);
        $newRow->setRowId($sharedCodeRowIds[1]);
        $newRow->setConfiguration(['code_content' => ['dev-bar']]);
        $components->updateConfigurationRow($newRow);

        $configuration = [
            'shared_code_id' => $sharedConfigurationId,
            'shared_code_row_ids' => $sharedCodeRowIds,
            'parameters' => [
                'some_parameter' => 'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .',
                'some_other_parameter' =>
                    ['foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .']
            ],
        ];
        $logger = new TestLogger();
        $sharedCodeResolver = new SharedCodeResolver($clientWrapper, $logger);
        $jobDefinition = new JobDefinition($configuration, $this->component, '123', '234', [], '123', false);
        $newJobDefinition = $sharedCodeResolver->resolveSharedCode([$jobDefinition])[0];
        self::assertEquals(
            [
                'parameters' => [
                    'some_parameter' =>
                        'foo is {{ foo }} and {{non-existent}} and {{ first_code }} and {{ secondCode }} .',
                    'some_other_parameter' => [
                        'SELECT * FROM {{tab1}} LEFT JOIN {{tab2}} ON b.a_id = a.id',
                        'dev-bar',
                    ],
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
}
