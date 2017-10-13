<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
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
    /**
     * @var Temp
     */
    private $temp;

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
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
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

    private function getRunner($handler, &$encryptor = null)
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

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger('null');
        $containerLogger->pushHandler($handler);
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

        /** @var JobMapper $jobMapperStub */
        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $encryptor = new ObjectEncryptor();
        $ecWrapper = new ComponentWrapper(hash('sha256', uniqid()));
        $ecWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper = new ComponentProjectWrapper(hash('sha256', uniqid()));
        $ecpWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper->setProjectId($tokenInfo['owner']['id']);
        $encryptor->pushWrapper($ecWrapper);
        $encryptor->pushWrapper($ecpWrapper);
        $encryptor->pushWrapper(new BaseWrapper(hash('sha256', uniqid())));

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        $runner = new Runner(
            $this->temp,
            $encryptor,
            $storageServiceStub,
            $loggersServiceStub,
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
        $runner = $this->getRunner(new NullHandler());
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

    public function testRunDisabled()
    {
        $runner = $this->getRunner(new NullHandler());
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
        ], $this->getComponent(), null, null, [], null, true);
        $jobDefinitions = [$jobDefinition1, $jobDefinition2];
        $runner->run(
            $jobDefinitions,
            'run',
            'run',
            '1234567'
        );
        $this->assertTrue($this->client->tableExists('in.c-docker-test.mytable'));
        $this->assertFalse($this->client->tableExists('in.c-docker-test.mytable-2'));
    }

    public function testRowMetadata()
    {
        $runner = $this->getRunner(new NullHandler());
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
        $runner = $this->getRunner(new NullHandler());

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

    public function testOutput()
    {
        $runner = $this->getRunner(new NullHandler());
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
