<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
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

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client(['token' => STORAGE_API_TOKEN]);
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
            "dummy"
        );
        return $runner;
    }

    public function testRunnerPipeline()
    {
        $dataDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
        $this->client->uploadFile(
            $dataDir . 'texty.zip',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'pipeline'])
        );
        $this->client->uploadFile(
            $dataDir . 'radio.zip',
            (new FileUploadOptions())->setTags(['docker-bundle-test', 'pipeline'])
        );

        $configurationData = [
            'storage' => [
                'input' => [
                    'files' => [[
                        'tags' => ['pipeline']
                    ]]
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'radio.csv',
                            'destination' => 'out.c-docker-pipeline.radio'
                        ],
                        [
                            'source' => 'texty.csv',
                            'destination' => 'out.c-docker-pipeline.texty'
                        ],
                    ]
                ]
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/radio.csv", stringsAsFactors = FALSE, encoding = "UTF-8");',
                    'data$rev <- unlist(lapply(data[["text"]], function(x) { paste(rev(strsplit(x, NULL)[[1]]), collapse=\'\') }))',
                    'write.csv(data, file = "/data/out/tables/radio.csv", row.names = FALSE)',
                    'data <- read.csv(file = "/data/in/tables/texty.csv", stringsAsFactors = FALSE, encoding = "UTF-8");',
                    'data$rev <- unlist(lapply(data[["text"]], function(x) { paste(rev(strsplit(x, NULL)[[1]]), collapse=\'\') }))',
                    'write.csv(data, file = "/data/out/tables/texty.csv", row.names = FALSE)',
                ]
            ],
            'processors' => [
                'before' => [
                    [
                        'definition' => [
                            'component' => 'keboola.processor.unzipper',
                        ],
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor.move-files',
                        ],
                        'parameters' => ['direction' => 'tables']
                    ],
                    [
                        'definition' => [
                            'component' => 'keboola.processor.iconv',
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
                    'type' => 'quayio',
                    'uri' => 'keboola/r-transformation',
                    'tag' => '0.0.14',
                ],
            ],
        ];

        $runner = $this->getRunner(new NullHandler());
        $runner->run(
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
            'run',
            'run',
            '1234567'
        );

        $csvData = $this->client->exportTable('out.c-docker-pipeline.radio');
        $data = Client::parseCsv($csvData);
        $this->assertEquals(9, count($data));
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('text', $data[0]);
        $this->assertArrayHasKey('tag', $data[0]);
        $this->assertArrayHasKey('rev', $data[0]);
        $csvData = $this->client->exportTable('out.c-docker-pipeline.texty');
        $data = Client::parseCsv($csvData);
        $this->assertEquals(4, count($data));
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('title', $data[0]);
        $this->assertArrayHasKey('text', $data[0]);
        $this->assertArrayHasKey('tags', $data[0]);
    }

    public function testImageParametersDecrypt()
    {
        $configurationData = [
           'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptor);
        /** @var ObjectEncryptor $encryptor */
        $encrypted = $encryptor->encrypt('someString');

        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'quay.io/keboola/docker-custom-php:0.0.1',
                    'build_options' => [
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
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
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
        $this->assertEquals('someString', $config['image_parameters']['#encrypted']);
    }

    public function testImageParametersEnvironment()
    {
        $configurationData = [
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptor);
        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'quay.io/keboola/docker-custom-php:0.0.1',
                    'build_options' => [
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        // also attempt to pass the token to verify that it does not work
                        'entry_point' => 'echo KBC_PARAMETER_FOO=$KBC_PARAMETER_FOO',
                    ],
                ],
                'configuration_format' => 'json',
                'inject_environment' => true,
            ],
        ];

        $runner->run(
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
            'run',
            'run',
            '1234567'
        );

        $ret = $handler->getRecords();
        $this->assertGreaterThan(0, count($ret));
        $this->assertLessThan(3, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $this->assertContains('KBC_PARAMETER_FOO=bar', $ret[0]['message']);
    }

    public function testImageParametersNoDecrypt()
    {
        $configurationData = [
            'parameters' => [
                'foo' => 'bar'
            ]
        ];
        $handler = new TestHandler();
        $runner = $this->getRunner($handler, $encryptor);
        /** @var ObjectEncryptor $encryptor */
        $encrypted = $encryptor->encrypt('someString');

        $componentData = [
            'id' => 'docker-dummy-component',
            'type' => 'other',
            'name' => 'Docker Pipe test',
            'description' => 'Testing Docker',
            'data' => [
                'definition' => [
                    'type' => 'builder',
                    'uri' => 'quay.io/keboola/docker-custom-php:0.0.1',
                    'build_options' => [
                        'repository' => [
                            'uri' => 'https://github.com/keboola/docker-demo-app.git',
                            'type' => 'git'
                        ],
                        'commands' => [],
                        'entry_point' => 'cat /data/config.json',
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
            $componentData,
            uniqid('test-'),
            $configurationData,
            [],
            'run',
            'dry-run',
            '1234567'
        );

        $ret = $handler->getRecords();
        $this->assertEquals(1, count($ret));
        $this->assertArrayHasKey('message', $ret[0]);
        $config = json_decode($ret[0]['message'], true);
        $this->assertEquals('bar', $config['parameters']['foo']);
        $this->assertEquals('bar', $config['image_parameters']['foo']);
        $this->assertEquals('pond', $config['image_parameters']['baz']['lily']);
        $this->assertEquals($encrypted, $config['image_parameters']['#encrypted']);
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
            $componentData,
            'test-config',
            $configurationData,
            [],
            'run',
            'run',
            '1234567'
        );

        $this->assertTrue($this->client->tableExists('out.c-keboola-docker-demo-app-test-config.sliced'));
        $this->client->dropBucket('out.c-keboola-docker-demo-app-test-config', ['force' => true]);
    }

    public function testGetSanitizedComponentId()
    {
        $runner = $this->getRunner(new NullHandler());
        $reflection = new \ReflectionMethod($runner, 'getSanitizedComponentId');
        $reflection->setAccessible(true);


        $this->assertEquals('keboola-ex-generic', $reflection->invoke($runner, 'keboola.ex-generic'));
        $this->assertEquals('ex-generic', $reflection->invoke($runner, 'ex-generic'));
        $this->assertEquals('keboola-ex-generic', $reflection->invoke($runner, 'keboola.ex.generic'));
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
                    'uri' => 'quay.io/keboola/docker-custom-php:0.0.1',
                    'build_options' => [
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
            $componentData,
            'test-configuration',
            [],
            [],
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
                    'uri' => 'quay.io/keboola/docker-custom-php:0.0.1',
                    'build_options' => [
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
            $componentData,
            'test-configuration',
            [],
            [],
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
                $componentData,
                'test-config',
                $configurationData,
                [],
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
            ],
        ];

        $runner->run(
            $componentData,
            'test-config',
            $configurationData,
            [],
            'run',
            'run',
            '1234567'
        );

        $this->assertTrue($this->client->tableExists('in.c-keboola-docker-demo-app-test-config.sliced'));
        $this->client->dropBucket('in.c-keboola-docker-demo-app-test-config', ['force' => true]);
    }
}
