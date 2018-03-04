<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RunnerConfigRowsTest extends KernelTestCase
{
    /**
     * @var Client
     */
    private $client;

    protected function clearBuckets()
    {
        foreach (['in.c-docker-test', 'out.c-docker-test'] as $bucket) {
            try {
                $this->client->dropBucket($bucket, ['force' => true]);
            } catch (ClientException $e) {
                if ($e->getCode() != 404) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param array $componentData
     * @param $configId
     * @param array $configData
     * @param array $state
     * @return JobDefinition[]
     */
    protected function prepareJobDefinitions(array $componentData, $configId, array $configData, array $state)
    {
        $jobDefinition = new JobDefinition($configData, new Component($componentData), $configId, null, $state);

        return [$jobDefinition];
    }

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]
        );
        $this->clearBuckets();

        // Create buckets
        $this->client->createBucket('docker-test', Client::STAGE_IN, 'Docker TestSuite');
        $this->client->createBucket('docker-test', Client::STAGE_OUT, 'Docker TestSuite');

        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(['docker-bundle-test']);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file['id']);
        }

        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        self::bootKernel();
    }

    public function tearDown()
    {
        $this->clearBuckets();
        parent::tearDown();
    }

    /**
     * @param HandlerInterface|null $logHandler
     * @param HandlerInterface|null $containerLogHandler
     * @return LoggersService
     */
    private function getLoggersServiceStub(HandlerInterface $logHandler = null, HandlerInterface $containerLogHandler = null)
    {
        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        if ($logHandler) {
            $log->pushHandler($logHandler);
        }
        $containerLogger = new ContainerLogger('null');
        $containerLogger->pushHandler(new NullHandler());
        if ($containerLogHandler) {
            $containerLogger->pushHandler($containerLogHandler);
        }
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $loggersServiceStub->expects($this->any())
            ->method('getLog')
            ->will($this->returnValue($log))
        ;
        $loggersServiceStub->expects($this->any())
            ->method('getContainerLog')
            ->will($this->returnValue($containerLogger))
        ;
        return $loggersServiceStub;
    }

    /**
     * @param LoggersService $loggersService
     * @return Runner
     */
    private function getRunner(LoggersService $loggersService)
    {
        $tokenInfo = $this->client->verifyToken();
        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $storageServiceStub->expects($this->any())
            ->method('getClient')
            ->will($this->returnValue($this->client))
        ;
        $storageServiceStub->expects($this->any())
            ->method('getTokenData')
            ->will($this->returnValue($tokenInfo))
        ;
        /** @var JobMapper $jobMapperStub */
        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.r-transformation');
        $encryptorFactory->setProjectId($tokenInfo["owner"]["id"]);

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        $runner = new Runner(
            $encryptorFactory,
            $storageServiceStub,
            $loggersService,
            $jobMapperStub,
            "dummy",
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );

        return $runner;
    }

    /**
     * Transform metadata into a key-value array
     *
     * @param $metadata
     * @return array
     */
    private function getMetadataValues($metadata)
    {
        $result = [];
        foreach ($metadata as $item) {
            $result[$item['provider']][$item['key']] = $item['value'];
        }

        return $result;
    }

    private function getComponent()
    {
        $componentData = [
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'mkdir /data/out/tables/mytable.csv.gz && '
                            . 'chmod 000 /data/out/tables/mytable.csv.gz && '
                            . 'touch /data/out/tables/mytable.csv.gz/part1 && '
                            . 'echo "value1" > /data/out/tables/mytable.csv.gz/part1 && '
                            . 'chmod 000 /data/out/tables/mytable.csv.gz/part1 && '
                            . 'touch /data/out/tables/mytable.csv.gz/part2 && '
                            . 'echo "value2" > /data/out/tables/mytable.csv.gz/part2 && '
                            . 'chmod 000 /data/out/tables/mytable.csv.gz/part2'
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];
        return new Component($componentData);
    }

    public function testRunMultipleRows()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        $this->assertTrue($this->client->tableExists('in.c-docker-test.mytable'));
        $this->assertTrue($this->client->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRunMultipleRowsFiltered()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'row-1');
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'row-2');
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567',
            'row-2'
        );
        $this->assertTrue($this->client->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRunUnknownRow()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'row-1');
        $jobDefinitions = [$jobDefinition1];
        try {
            $runner->run(
                $jobDefinitions,
                'run',
                'run',
                '1234567',
                'row-2'
            );
            $this->fail("Exception not caught.");
        } catch (UserException $e) {
            $this->assertEquals("Row row-2 not found.", $e->getMessage());
        }
    }

    public function testRunEmptyJobDefinitions()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());
        $runner->run(
            [],
            'run',
            'run',
            '1234567'
        );
    }

    public function testRunDisabled()
    {
        $logHandler = new TestHandler();
        $loggersServiceStub = $this->getLoggersServiceStub($logHandler);
        $runner = $this->getRunner($loggersServiceStub);
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'disabled-row', true);
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        $this->assertTrue($logHandler->hasInfoThatContains('Skipping disabled configuration: my-config, version: 1, row: disabled-row'));
        $this->assertTrue($this->client->tableExists('in.c-docker-test.mytable'));
        $this->assertFalse($this->client->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRunRowDisabled()
    {
        $logHandler = new TestHandler();
        $loggersServiceStub = $this->getLoggersServiceStub($logHandler);
        $runner = $this->getRunner($loggersServiceStub);
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'my-config', 1, [], 'disabled-row', true);
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567',
            'disabled-row'
        );
        $this->assertTrue($logHandler->hasInfoThatContains('Force running disabled configuration: my-config, version: 1, row: disabled-row'));
        $this->assertTrue($this->client->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRowMetadata()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'config', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent(), 'config', null, [], 'row-2');

        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        $metadata = new Metadata($this->client);
        $table1Metadata = $this->getMetadataValues($metadata->listTableMetadata('in.c-docker-test.mytable'));
        $table2Metadata = $this->getMetadataValues($metadata->listTableMetadata('in.c-docker-test.mytable-2'));

        $this->assertArrayHasKey('KBC.createdBy.component.id', $table1Metadata['system']);
        $this->assertArrayHasKey('KBC.createdBy.configuration.id', $table1Metadata['system']);
        $this->assertArrayHasKey('KBC.createdBy.configurationRow.id', $table1Metadata['system']);
        $this->assertEquals('docker-demo', $table1Metadata['system']['KBC.createdBy.component.id']);
        $this->assertEquals('config', $table1Metadata['system']['KBC.createdBy.configuration.id']);
        $this->assertEquals('row-1', $table1Metadata['system']['KBC.createdBy.configurationRow.id']);

        $this->assertArrayHasKey('KBC.createdBy.component.id', $table2Metadata['system']);
        $this->assertArrayHasKey('KBC.createdBy.configuration.id', $table2Metadata['system']);
        $this->assertArrayHasKey('KBC.createdBy.configurationRow.id', $table2Metadata['system']);
        $this->assertEquals('docker-demo', $table2Metadata['system']['KBC.createdBy.component.id']);
        $this->assertEquals('config', $table2Metadata['system']['KBC.createdBy.configuration.id']);
        $this->assertEquals('row-2', $table2Metadata['system']['KBC.createdBy.configurationRow.id']);
    }

    public function testExecutorStoreRowState()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());

        $component = new Components($this->client);
        try {
            $component->deleteConfiguration('docker-demo', 'test-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $configuration = new Configuration();
        $configuration->setComponentId('docker-demo');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('test-configuration');
        $component->addConfiguration($configuration);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $component->addConfigurationRow($configurationRow);

        $componentData = [
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'echo "{\"baz\": \"bar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $jobDefinition1 = new JobDefinition([], new Component($componentData), 'test-configuration', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition([], new Component($componentData), 'test-configuration', null, [], 'row-2');

        $runner->run(
            [$jobDefinition1, $jobDefinition2],
            'run',
            'run',
            '1234567'
        );

        $component = new Components($this->client);
        $configuration = $component->getConfiguration('docker-demo', 'test-configuration');

        $this->assertEquals([], $configuration['state']);
        $this->assertEquals(['baz' => 'bar'], $configuration['rows'][0]['state']);
        $this->assertEquals(['baz' => 'bar'], $configuration['rows'][1]['state']);
        $component->deleteConfiguration('docker-demo', 'test-configuration');
    }

    public function testExecutorStoreRowStateWithProcessor()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());

        $component = new Components($this->client);
        try {
            $component->deleteConfiguration('docker-demo', 'test-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $configuration = new Configuration();
        $configuration->setComponentId('docker-demo');
        $configuration->setName('Test configuration');
        $configuration->setConfigurationId('test-configuration');
        $component->addConfiguration($configuration);

        $configData = [
            'processors' => [
                'after' => [
                    [
                        'definition' => [
                            'component'=> 'keboola.processor-move-files'
                        ],
                        'parameters' => [
                            'direction' => 'tables'
                        ]
                    ]
                ]
            ]
        ];

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-1');
        $configurationRow->setName('Row 1');
        $configurationRow->setConfiguration($configData);

        $component->addConfigurationRow($configurationRow);

        $configurationRow = new ConfigurationRow($configuration);
        $configurationRow->setRowId('row-2');
        $configurationRow->setName('Row 2');
        $configurationRow->setConfiguration($configData);

        $component->addConfigurationRow($configurationRow);

        $componentData = [
            'id' => 'docker-demo',
            'type' => 'other',
            'name' => 'Docker State test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'keboola/docker-custom-php',
                    'tag' => 'latest',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'echo "{\"baz\": \"bar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $jobDefinition1 = new JobDefinition($configData, new Component($componentData), 'test-configuration', null, [], 'row-1');
        $jobDefinition2 = new JobDefinition($configData, new Component($componentData), 'test-configuration', null, [], 'row-2');

        $runner->run(
            [$jobDefinition1, $jobDefinition2],
            'run',
            'run',
            '1234567'
        );

        $component = new Components($this->client);
        $configuration = $component->getConfiguration('docker-demo', 'test-configuration');

        $this->assertEquals([], $configuration['state']);
        $this->assertEquals(['baz' => 'bar'], $configuration['rows'][0]['state']);
        $this->assertEquals(['baz' => 'bar'], $configuration['rows'][1]['state']);
        $component->deleteConfiguration('docker-demo', 'test-configuration');
    }

    public function testOutput()
    {
        $runner = $this->getRunner($this->getLoggersServiceStub());
        $jobDefinition1 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinition2 = new JobDefinition([
            "storage" => [
                "output" => [
                    "tables" => [
                        [
                            "source" => "mytable.csv.gz",
                            "destination" => "in.c-docker-test.mytable-2",
                            "columns" => ["col1"]
                        ]
                    ]
                ]
            ]
        ], $this->getComponent());
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $outputs = $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        $this->assertCount(2, $outputs);
        $this->assertCount(1, $outputs[0]->getImages());
        $this->assertCount(1, $outputs[1]->getImages());
    }
}
