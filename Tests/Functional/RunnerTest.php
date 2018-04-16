<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
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
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RunnerTest extends KernelTestCase
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
        $jobDefinition = new JobDefinition($configData, new Component($componentData), $configId, 'v123', $state);
        return [$jobDefinition];
    }

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
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

    private function getRunner($handler, &$encryptorFactory = null)
    {
        $tokenInfo = $this->client->verifyToken();
        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method('getClient')
            ->will($this->returnValue($this->client));
        $storageServiceStub->expects($this->any())
            ->method('getTokenData')
            ->will($this->returnValue($tokenInfo));

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger('null');
        $containerLogger->pushHandler($handler);
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggersServiceStub->expects($this->any())
            ->method('getLog')
            ->will($this->returnValue($log));
        $loggersServiceStub->expects($this->any())
            ->method('getContainerLog')
            ->will($this->returnValue($containerLogger));

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

    public function testGetOauthUrl()
    {
        $clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will($this->returnValue(['services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));

        $storageServiceStub = self::getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method('getClient')
            ->will($this->returnValue($clientMock));

        $loggersServiceStub = self::getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var JobMapper $jobMapperStub */
        $jobMapperStub = self::getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        $runner = new Runner(
            $encryptorFactory,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapperStub,
            "dummy",
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );

        $method = new \ReflectionMethod($runner, 'getOauthUrlV3');
        $method->setAccessible(true);
        $response = $method->invoke($runner);
        self::assertEquals($response, 'https://someurl');
    }

    public function testRunnerPipeline()
    {
        $components = [
            [
                "id" => "keboola.processor-last-file",
                "data" => [
                    "definition" => [
                      "type" => "aws-ecr",
                      "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-last-file",
                      "tag" => "0.3.0",
                    ],
                ],
            ],

            [
                "id" => "keboola.processor-iconv",
                "data" => [
                    "definition" => [
                      "type" => "aws-ecr",
                      "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-iconv",
                      "tag" => "2.0.3",
                    ],
                ],
            ],
            [
                "id" => "keboola.processor-move-files",
                "data" => [
                    "definition" => [
                        "type" => "aws-ecr",
                        "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-move-files",
                        "tag" => "1.1.0",
                    ],
                ],
            ],
            [
                "id" => "keboola.processor-decompress",
                "data" => [
                    "definition" => [
                        "type" => "aws-ecr",
                        "uri" => "147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress",
                        "tag" => "0.1.0",
                    ],
                ],
            ],
        ];

        $clientMock = $this->getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $clientMock->expects($this->any())
            ->method('indexAction')
            ->will($this->returnValue(['components' => $components, 'services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));
        $this->client = $clientMock;

        $dataDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
        $this->client->uploadFile(
            $dataDir . 'texty.csv.gz',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'texty.csv.gz'])
        );

        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [[
                        'tags' => ['texty.csv.gz']
                    ]]
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'texty.csv',
                            'destination' => 'out.c-docker-pipeline.texty'
                        ],
                    ]
                ]
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/texty.csv", stringsAsFactors = FALSE, encoding = "UTF-8");',
                    'data$rev <- unlist(lapply(data[["text"]], function(x) { paste(rev(strsplit(x, NULL)[[1]]), collapse=\'\') }))',
                    'write.csv(data, file = "/data/out/tables/texty.csv", row.names = FALSE)',
                ]
            ],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor-last-file',
                        ],
                        'parameters' => ['tag' => 'texty.csv.gz']
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor-decompress',
                        ],
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor-move-files',
                        ],
                        'parameters' => ['direction' => 'tables']
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor-iconv',
                        ],
                        'parameters' => ['source_encoding' => 'CP1250']
                    ],
                ],
            ],
        ];

        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation',
                    'tag' => '1.1.1',
                ],
            ],
        ];

        $runner = $this->getRunner(new NullHandler());
        $outputs = $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567'
        );
        $this->assertEquals(
            [
                0 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-last-file:0.3.0',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-last-file@sha256:0c730bd4d91ca6962d72cd0d878a97857a1ef7c37eadd2eafd770ca26e627b0e'
                    ],
                ],
                1 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress:0.1.0',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-decompress@sha256:fb54c9d22b8b3de5a8a528b7d594ecad9d413a23f5601186111fdddc503b0349'
                    ],
                ],
                2 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-move-files:1.1.0',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-move-files@sha256:0d0a5275dbf68b1d54abc4a8dd081559e5662d515447613e5d6c9c0177e36d38'
                    ],
                ],
                3 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-iconv:2.0.3',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-iconv@sha256:30ed1d74f9aa41ac0f47d30c3ae43829f4f39f14c14d309ed2081643a4258d3b'
                    ],
                ],
                4 => [
                    'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation:1.1.1',
                    'digests' => [
                        '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.r-transformation@sha256:a332fdf1b764b329230dd420f54d642d042b8552f6b499cf26cd49ee504904d6'
                    ],
                ]
            ],
            $outputs[0]->getImages()
        );
        $lines = explode("\n", $outputs[0]->getProcessOutput());
        $lines = array_map(function ($line) {
            return substr($line, 23); // skip the date of event
        }, $lines);
        $this->assertEquals([
            0 => ' : Initializing R transformation',
            1 => ' : Running R script',
            2 => ' : R script finished',
        ], $lines);

        $csvData = $this->client->getTableDataPreview('out.c-docker-pipeline.texty');
        $data = Client::parseCsv($csvData);
        $this->assertEquals(4, count($data));
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('title', $data[0]);
        $this->assertArrayHasKey('text', $data[0]);
        $this->assertArrayHasKey('tags', $data[0]);
        $this->assertEquals('v123', $outputs[0]->getConfigVersion());
    }

    public function testImageParametersDecrypt()
    {
        $configurationData = [
           'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptorFactory);
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('someString');

        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
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
                        // also attempt to pass the token to verify that it does not work
                        'entry_point' => 'cat /data/config.json && echo $KBC_TOKEN',
                    ],
                ],
                'configuration_format' => 'json',
                'image_parameters' => [
                    'foo' => 'bar',
                    'baz' => [
                        'lily' => 'pond'
                    ],
                    '#encrypted' => $encrypted
                ]
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $ret = $handler->getRecords();
        $this->assertGreaterThan(0, count($ret));
        $this->assertLessThan(3, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        // verify that the token is not passed by default
        $this->assertNotContains(STORAGE_API_TOKEN, $ret[0]['message']);
        $this->assertEquals('bar', $config['parameters']['foo']);
        $this->assertEquals('bar', $config['image_parameters']['foo']);
        $this->assertEquals('pond', $config['image_parameters']['baz']['lily']);
        $this->assertEquals('[hidden]', $config['image_parameters']['#encrypted']);
    }

    public function testClearState()
    {
        $state = ['key' => 'value'];
        $runner = $this->getRunner(new NullHandler());
        $cmp = new Components($this->client);
        try {
            $cmp->deleteConfiguration('docker-demo', 'dummy-configuration');
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $cfg = new Configuration();
        $cfg->setComponentId('docker-demo');
        $cfg->setConfigurationId('dummy-configuration');
        $cfg->setConfiguration([]);
        $cfg->setName('Test configuration');
        $cfg->setState($state);
        $cmp->addConfiguration($cfg);

        $componentData = [
            'id' => 'docker-demo',
            'type' => 'other',
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
                        'entry_point' => 'cat /data/config.json',
                    ],
                ],
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'dummy-configuration',
                [],
                $state
            ),
            'run',
            'run',
            '1234567'
        );
        $cfg = $cmp->getConfiguration('docker-demo', 'dummy-configuration');
        self::assertEquals([], $cfg['state']);
        $cmp->deleteConfiguration('docker-demo', 'dummy-configuration');
    }

    public function testExecutorDefaultBucketWithDot()
    {
        // Create bucket
        if (!$this->client->bucketExists('in.c-docker-test')) {
            $this->client->createBucket('docker-test', Client::STAGE_IN, 'Docker Testsuite');
        }

        // Create table
        if (!$this->client->tableExists('in.c-docker-test.test')) {
            $csv = new CsvFile($this->temp->getTmpFolder() . '/upload.csv');
            $csv->writeRow(['id', 'text']);
            $csv->writeRow(['test', 'test']);
            $this->client->createTableAsync('in.c-docker-test', 'test', $csv);
            $this->client->setTableAttribute('in.c-docker-test.test', 'attr1', 'val1');
            unset($csv);
        }

        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => '4',
            ]
        ];
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
                'configuration_format' => 'json',
                'default_bucket' => true,
                'default_bucket_stage' => 'out',
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'test-config',
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $this->assertTrue($this->client->tableExists('out.c-keboola-docker-demo-app-test-config.sliced'));
        $this->client->dropBucket('out.c-keboola-docker-demo-app-test-config', ['force' => true]);
    }

    public function testExecutorStoreState()
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
        $configuration->setState(json_encode(['foo' => 'bar']));
        $component->addConfiguration($configuration);

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
                        'entry_point' => 'echo "{\"baz\": \"fooBar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'test-configuration',
                [],
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $component = new Components($this->client);
        $configuration = $component->getConfiguration('docker-demo', 'test-configuration');
        $this->assertEquals(['baz' => 'fooBar'], $configuration['state']);
        $component->deleteConfiguration('docker-demo', 'test-configuration');
    }

    public function testExecutorStoreStateWithProcessor()
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
        $configuration->setState(json_encode(['foo' => 'bar']));
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
        $configuration->setConfiguration($configData);
        $component->addConfiguration($configuration);

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
                        'entry_point' => 'echo "{\"baz\": \"fooBar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'test-configuration',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $component = new Components($this->client);
        $configuration = $component->getConfiguration('docker-demo', 'test-configuration');
        $this->assertEquals(['baz' => 'fooBar'], $configuration['state']);
        $component->deleteConfiguration('docker-demo', 'test-configuration');
    }

    public function testExecutorNoStoreState()
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
                        'entry_point' => 'echo "{\"baz\": \"fooBar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'test-configuration',
                [],
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $component = new Components($this->client);
        try {
            $component->getConfiguration('docker-demo', 'test-configuration');
            $this->fail("Configuration should not exist");
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }

    public function testExecutorStateNoConfigId()
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
                        'entry_point' => 'echo "{\"baz\": \"fooBar\"}" > /data/out/state.json',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];
        $configData = [
            'parameters' => [
                'foo' => 'bar'
            ]
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                '',
                $configData,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $component = new Components($this->client);
        try {
            $component->getConfiguration('docker-demo', 'test-configuration');
            $this->fail("Configuration should not exist");
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }

    public function testExecutorNoConfigIdNoMetadata()
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
                        'entry_point' => 'echo "id,name\n1,test" > /data/out/tables/data.csv',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];
        $configData = [
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'data.csv',
                            'destination' => 'in.c-docker-demo-whatever.some-table'
                        ]
                    ]
                ]
            ]
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                null,
                $configData,
                []
            ),
            'run',
            'run',
            '1234567'
        );
        $metadataApi = new Metadata($this->client);
        $bucketMetadata = $metadataApi->listBucketMetadata('in.c-docker-demo-whatever');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo'
            ]
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));
    }

    public function testExecutorInvalidConfiguration()
    {
        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [
                        [
                            'tags' => ['tde'],
                            /* unrecognized option -> */
                            'filterByRunId' => true,
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'mode' => true,
                'credentials' => 'tde-exporter-tde-bug-32',
            ]
        ];
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
                'configuration_format' => 'json',
                'default_bucket' => true,
                'default_bucket_stage' => 'out',
            ],
        ];

        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'test-config',
                    $configurationData,
                    []
                ),
                'run',
                'run',
                '1234567'
            );
            $this->fail("Invalid configuration must fail.");
        } catch (UserException $e) {
            $this->assertContains('Unrecognized option "filterByRunId"', $e->getMessage());
        }
    }

    public function testExecutorDefaultBucketNoStage()
    {
        // Initialize buckets
        try {
            $this->client->dropBucket('in.c-keboola-docker-demo-app-test-config', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        try {
            $this->client->dropBucket('in.c-docker-test', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $this->client->createBucket('docker-test', Client::STAGE_IN, 'Docker Testsuite');

        // Create table
        if (!$this->client->tableExists('in.c-docker-test.test')) {
            $csv = new CsvFile($this->temp->getTmpFolder() . '/upload.csv');
            $csv->writeRow(['id', 'text']);
            $csv->writeRow(['test', 'test']);
            $this->client->createTableAsync('in.c-docker-test', 'test', $csv);
            $this->client->setTableAttribute('in.c-docker-test.test', 'attr1', 'val1');
            unset($csv);
        }

        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => '4',
            ]
        ];
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
                'configuration_format' => 'json',
                'default_bucket' => true,
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'test-config',
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $this->assertTrue($this->client->tableExists('in.c-keboola-docker-demo-app-test-config.sliced'));
        $this->client->dropBucket('in.c-keboola-docker-demo-app-test-config', ['force' => true]);
    }

    public function testExecutorSyncActionNoStorage()
    {
        // Initialize buckets
        try {
            $this->client->dropBucket('in.c-keboola-docker-demo-app-test-config', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        try {
            $this->client->dropBucket('in.c-docker-test', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $this->client->createBucket('docker-test', Client::STAGE_IN, 'Docker Testsuite');

        // Create table
        if (!$this->client->tableExists('in.c-docker-test.test')) {
            $csv = new CsvFile($this->temp->getTmpFolder() . '/upload.csv');
            $csv->writeRow(['id', 'text']);
            $csv->writeRow(['test', 'test']);
            $this->client->createTableAsync('in.c-docker-test', 'test', $csv);
            $this->client->setTableAttribute('in.c-docker-test.test', 'attr1', 'val1');
            unset($csv);
        }

        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => '4',
            ]
        ];
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
                'configuration_format' => 'json',
                'synchronous_actions' => [],
                'default_bucket' => true,
            ],
        ];

        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'test-config',
                    $configurationData,
                    []
                ),
                'some-sync-action',
                'run',
                '1234567'
            );
            $this->fail("Component must fail");
        } catch (UserException $e) {
            $this->assertContains("File '/data/in/tables/source.csv' not found", $e->getMessage());
        }
    }

    public function testExecutorNoStorage()
    {
        // Initialize buckets
        try {
            $this->client->dropBucket('in.c-keboola-docker-demo-app-test-config', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        try {
            $this->client->dropBucket('in.c-docker-test', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
        $this->client->createBucket('docker-test', Client::STAGE_IN, 'Docker Testsuite');

        // Create table
        if (!$this->client->tableExists('in.c-docker-test.test')) {
            $csv = new CsvFile($this->temp->getTmpFolder() . '/upload.csv');
            $csv->writeRow(['id', 'text']);
            $csv->writeRow(['test', 'test']);
            $this->client->createTableAsync('in.c-docker-test', 'test', $csv);
            $this->client->setTableAttribute('in.c-docker-test.test', 'attr1', 'val1');
            unset($csv);
        }

        $configurationData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.test',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => '4',
            ]
        ];
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
                'configuration_format' => 'json',
                'staging_storage' => [
                    'input' => 'none'
                ],
                'default_bucket' => true,
            ],
        ];

        try {
            $runner->run(
                $this->prepareJobDefinitions(
                    $componentData,
                    'test-config',
                    $configurationData,
                    []
                ),
                'run',
                'run',
                '1234567'
            );
            $this->fail("Component must fail");
        } catch (UserException $e) {
            $this->assertContains("File '/data/in/tables/source.csv' not found", $e->getMessage());
        }
    }

    public function testExecutorApplicationError()
    {
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
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
                        'entry_point' => 'echo "Class 2 error" >&2 && exit 2',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        try {
            $runner->run($this->prepareJobDefinitions($componentData, 'test-config', [], []), 'run', 'run', '1234567');
            $this->fail("Application exception must be raised");
        } catch (ApplicationException $e) {
            $this->assertContains('Application error', $e->getMessage());
            $this->assertContains('Class 2 error', $e->getMessage());
        }
    }

    public function testExecutorUserError()
    {
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
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
                        'entry_point' => 'echo "Class 1 error" >&2 && exit 1',
                    ],
                ],
                'configuration_format' => 'json',
            ],
        ];

        try {
            $runner->run($this->prepareJobDefinitions($componentData, 'test-config', [], []), 'run', 'run', '1234567');
            $this->fail('User exception must be raised');
        } catch (UserException $e) {
            $this->assertContains('Class 1 error', $e->getMessage());
        }
    }

    public function testExecutorApplicationErrorDisabled()
    {
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
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
                        'entry_point' => 'echo "Class 2 error" >&2 && exit 2',
                    ],
                ],
                'configuration_format' => 'json',
                'logging' => [
                    'no_application_errors' => true,
                ],
            ],
        ];

        try {
            $runner->run($this->prepareJobDefinitions($componentData, 'test-config', [], []), 'run', 'run', '1234567');
            $this->fail("Application exception must not be raised.");
        } catch (UserException $e) {
            $this->assertNotContains('Application error', $e->getMessage());
            $this->assertContains('Class 2 error', $e->getMessage());
        }
    }

    public function testExecutorApplicationErrorDisabledButStillError()
    {
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'completely_invalid_uri',
                    'build_options' => [
                        'parent_type' => 'quayio',
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'echo "Class 2 error" >&2 && exit 2',
                    ],
                ],
                'configuration_format' => 'json',
                'logging' => [
                    'no_application_errors' => true,
                ],
            ],
        ];

        try {
            $runner->run($this->prepareJobDefinitions($componentData, 'test-config', [], []), 'run', 'run', '1234567');
            $this->fail("Application exception must be raised even though it is disabled.");
        } catch (ApplicationException $e) {
            $this->assertContains('Application error', $e->getMessage());
            $this->assertContains('Failed to pull parent image completely_invalid_uri', $e->getMessage());
        }
    }

    public function testExecutorInvalidInputMapping()
    {
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ]
            ]
        ];
        $config = [
            "storage" => [
                "input" => [
                    "tables" => [
                        [
                            "source" => "in.c-docker-test.test",
                            // erroneous lines
                            "foo" => "bar"
                        ]
                    ]
                ],
                "output" => [
                    "tables" => [
                        [
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        ]
                    ]
                ]
            ]
        ];

        try {
            $runner->run($this->prepareJobDefinitions($componentData, 'test-config', $config, []), 'run', 'run', '1234567');
            $this->fail("User exception must be raised");
        } catch (UserException $e) {
            $this->assertContains('Unrecognized option "foo" under "container.storage.input.tables.0"', $e->getMessage());
        }
    }

    public function testExecutorInvalidInputMapping2()
    {
        $runner = $this->getRunner(new NullHandler());

        $componentData = [
            'id' => 'keboola.docker-demo-app',
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ]
            ]
        ];
        $config = [
            "storage" => [
                "input" => [
                    "tables" => [
                        [
                            "source" => "in.c-docker-test.test",
                            // erroneous lines
                            "columns" => [
                                [
                                    "value" => "id",
                                    "label" => "id"
                                ],
                                [
                                    "value" => "col1",
                                    "label" => "col1"
                                ]
                            ]
                        ]
                    ]
                ],
                "output" => [
                    "tables" => [
                        [
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        ]
                    ]
                ]
            ]
        ];

        try {
            $runner->run($this->prepareJobDefinitions($componentData, 'test-config', $config, []), 'run', 'run', '1234567');
            $this->fail("User exception must be raised");
        } catch (UserException $e) {
            $this->assertContains('Invalid type for path "container.storage.input.tables.0.columns.0".', $e->getMessage());
        }
    }


    public function testExecutorSlicedFiles()
    {
        $runner = $this->getRunner(new NullHandler());

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

        $config = [
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
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                'test-configuration',
                $config,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $this->assertTrue($this->client->tableExists('in.c-docker-test.mytable'));
    }

    public function testAuthorizationDecrypt()
    {
        $configurationData = [
            'parameters' => [
                '#one' => 'bar',
                'two' => 'anotherBar'
            ],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#three' => 'foo',
                        'four' => 'anotherFoo'
                    ]
                ]
            ]
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptorFactory);
        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
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
                        'entry_point' => 'cat /data/config.json',
                    ],
                ],
                'configuration_format' => 'json'
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $ret = $handler->getRecords();
        $this->assertGreaterThan(0, count($ret));
        $this->assertLessThan(3, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        // verify that the token is not passed by default
        $this->assertNotContains(STORAGE_API_TOKEN, $ret[0]['message']);
        $this->assertEquals('[hidden]', $config['parameters']['#one']);
        $this->assertEquals('anotherBar', $config['parameters']['two']);
        $this->assertEquals('[hidden]', $config['authorization']['oauth_api']['credentials']['#three']);
        $this->assertEquals('anotherFoo', $config['authorization']['oauth_api']['credentials']['four']);
    }

    public function testTokenObfuscate()
    {
        $configurationData = [
            'parameters' => [
                'script' => [
                    "import os",
                    "print(os.environ['KBC_TOKEN'])",
                ],
            ],
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptorFactory);
        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Token test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                ],
                'forward_token' => true,
            ],
        ];

        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                uniqid('test-'),
                $configurationData,
                []
            ),
            'run',
            'run',
            '1234567'
        );

        $ret = $handler->getRecords();
        $this->assertGreaterThan(0, count($ret));
        $this->assertLessThan(3, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $output = '';
        foreach ($ret as $message) {
            $output .= $message['message'];
        }
        $this->assertNotContains(STORAGE_API_TOKEN, $output);
        $this->assertContains('[hidden]', $output);
    }
}
