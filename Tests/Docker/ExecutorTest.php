<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Executor;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Tests\Docker\Mock\Container as MockContainer;
use Keboola\DockerBundle\Tests\Docker\Mock\ContainerFactory;
use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class ExecutorTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $tmpDir;

    /**
     * @var Temp
     */
    private $temp;

    public function setUp()
    {
        // Create folders
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
        $this->tmpDir = $this->temp->getTmpFolder();

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            "token" => STORAGE_API_TOKEN,
        ]);

        // Delete bucket
        if ($this->client->bucketExists("in.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("in.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }
            $this->client->dropBucket("in.c-docker-test");
        }

        // Create bucket
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker Testsuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.test")) {
            $csv = new CsvFile($this->tmpDir . "/upload.csv");
            $csv->writeRow(["id", "text"]);
            $csv->writeRow(["test", "testtesttest"]);
            $this->client->createTableAsync("in.c-docker-test", "test", $csv);
        }

        $listFiles = new ListFilesOptions();
        $listFiles->setTags(['sandbox']);
        $listFiles->setRunId($this->client->getRunId());
        foreach ($this->client->listFiles($listFiles) as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    public function getLogService()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $logContainer = new ContainerLogger("null");
        $handler = new TestHandler();
        $logContainer->pushHandler($handler);
        $storageApiHandlerStub = $this->getMockBuilder(StorageApiHandler::class)
            ->disableOriginalConstructor()
            ->setMethods('handle')->getMock();
        $storageApiHandlerStub->method('handle')->willReturn(false);
        $logService = new LoggersService($log, $logContainer, $storageApiHandlerStub);
        return $logService;
    }

    public function testCreateAndDropDataDir()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $dummyConfig = array(
            "definition" => array(
                "type" => "dummy",
                "uri" => "dummy"
            )
        );
        $encryptor = new ObjectEncryptor();
        $container = new Container(Image::factory($encryptor, $log, $dummyConfig), $log, $containerLog);
        $fs = new Filesystem();
        $root = "/tmp/docker/" . uniqid("", true);
        $fs->mkdir($root);
        $container->createDataDir($root);
        $structure = array(
            $root . "/data",
            $root . "/data/in",
            $root . "/data/in/tables",
            $root . "/data/in/files",
            $root . "/data/out",
            $root . "/data/out/tables",
            $root . "/data/out/files"
        );
        $this->assertTrue($fs->exists($structure));

        foreach ($structure as $folder) {
            $fs->touch($folder . "/file");
        }
        $container->dropDataDir();
        $this->assertFalse($fs->exists($root . "/data"));
    }

    public function testDockerHubExecutorRun()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
        ];

        $config = [
            "storage" => [
                "input" => [
                    "tables" => [
                        [
                            "source" => "in.c-docker-test.test"
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
            ],
            "parameters" => [
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            ]
        ];


        $logService = $this->getLogService();
        $encryptor = new ObjectEncryptor();
        $containerFactory = new ContainerFactory($logService->getLog(), $logService->getContainerLog());
        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor(
            $this->client,
            $this->getLogService(),
            $containerFactory,
            $oauthClient,
            $encryptor,
            $this->tmpDir
        );

        $callback = function () use ($executor) {
            $fs = new Filesystem();
            $fs->dumpFile(
                $executor->getDataDir() . "/out/tables/sliced.csv",
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
            );
            $process = new Process('echo "Processed 1 rows."');
            $process->run();
            return $process;
        };
        $containerFactory->setRunMethod($callback);
        $executor->initialize($config, [], $imageConfig, false, 'run');
        $executor->run("testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertTrue(file_exists($executor->getDataDir() . '/out/tables/sliced.csv'));
        $this->assertEquals(
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
            file_get_contents($executor->getDataDir() . '/out/tables/sliced.csv')
        );

        $ret = $container->getRunCommand('test');
        // make sure that the token is NOT forwarded by default
        $this->assertNotContains(STORAGE_API_TOKEN, $ret);
    }


    public function testQuayIOExecutorRun()
    {
        $imageConfig = [
            "definition" => [
                "type" => "quayio",
                "uri" => "keboola/docker-demo-app"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
        ];

        $config = [
            "storage" => [
                "input" => [
                    "tables" => [
                        [
                            "source" => "in.c-docker-test.test"
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
            ],
            "parameters" => [
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            ]
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $logContainer = new ContainerLogger("null");
        $logContainer->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $logContainer);

        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile(
                $container->getDataDir() . "/out/tables/sliced.csv",
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
            );
            $process = new Process('echo "Processed 1 rows."');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertTrue(file_exists($container->getDataDir() . '/out/tables/sliced.csv'));
        $this->assertEquals(
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
            file_get_contents($container->getDataDir() . '/out/tables/sliced.csv')
        );
        $ret = $container->getRunCommand('test');
        // make sure that the token is NOT forwarded by default
        $this->assertNotContains(STORAGE_API_TOKEN, $ret);
    }

    public function testDockerHubPrivateExecutorRun()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId(123);
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $imageConfig = [
            "definition" => [
                "type" => "dockerhub-private",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => [
                    "#password" => $encryptor->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                    "username" => DOCKERHUB_PRIVATE_USERNAME
                ]
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
        ];

        $config = [
            "storage" => [
                "input" => [
                    "tables" => [
                        [
                            "source" => "in.c-docker-test.test"
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
            ],
            "parameters" => [
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            ]
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $logContainer = new ContainerLogger("null");
        $logContainer->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $logContainer);

        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile(
                $container->getDataDir() . "/out/tables/sliced.csv",
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
            );
            $process = new Process('echo "Processed 1 rows."');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertTrue(file_exists($container->getDataDir() . '/out/tables/sliced.csv'));
        $this->assertEquals(
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
            file_get_contents($container->getDataDir() . '/out/tables/sliced.csv')
        );
        $ret = $container->getRunCommand('test');
        // make sure that the token is NOT forwarded by default
        $this->assertNotContains(STORAGE_API_TOKEN, $ret);
    }

    public function testExecutorSandbox()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        ];

        $config = [
            "storage" => [
                "input" => [
                    "tables" => [
                        [
                            "source" => "in.c-docker-test.test"
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
            ],
            "parameters" => [
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            ]
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $containerLog);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $executor->storeDataArchive($container, ['sandbox', 'docker-test']);
        $this->assertFileExists(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'zip' . DIRECTORY_SEPARATOR . 'data.zip'
        );
        $listFiles = new ListFilesOptions();
        $listFiles->setTags(['sandbox']);
        $listFiles->setRunId($this->client->getRunId());
        $files = $this->client->listFiles($listFiles);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
    }


    public function testExecutorInvalidOutputMapping()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        ];

        $config = [
            "storage" => [
                "input" => [
                    "tables" => [
                        [
                            "source" => "in.c-docker-test.test"
                        ]
                    ]
                ],
                "output" => [
                    "tables" => [
                        [
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out",
                            // erroneous lines
                            "primary_key" => "col1",
                            "incremental" => 1
                        ]
                    ]
                ]
            ],
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $containerLog);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        try {
            $executor->initialize($container, $config, [], false);
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }


    public function testExecutorInvalidInputMapping()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
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
            ],
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $containerLog);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        try {
            $executor->initialize($container, $config, [], false);
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }


    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage Error in configuration: Invalid type for path
     */
    public function testExecutorInvalidInputMapping2()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
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
            ],
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $containerLog);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
    }

    public function testExecutorStoreNonEmptyStateFile()
    {
        // todo z tohohle jeste otestovat shouldStoreState metodu
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "process_timeout" => 1,
            "forward_token_details" => true
        ];

        $config = [
            "storage" => [],
            "parameters" => [
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            ]
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $containerLog);

        $callback = function () use ($container) {
            $process = new Process('sleep 1');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, ["lastUpdate" => "today"], false);
        $this->assertFileExists($this->tmpDir . "/data/in/state.json");
        $this->assertEquals(
            "{\n    \"lastUpdate\": \"today\"\n}",
            file_get_contents($this->tmpDir . "/data/in/state.json")
        );
    }

    public function testExecutorDefinitionParameters()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "configuration_format" => "yaml",
        ];

        $config = [
            "storage" => [
            ],
            "parameters" => [
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            ],
            "runtime" => [
                "foo" => "bar",
                "baz" => "next"
            ]
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log, $containerLog);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $configFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'config.yml';
        $this->assertFileExists($configFile);
        $config = Yaml::parse($configFile);
        $this->assertEquals('id', $config['parameters']['primary_key_column']);
        $this->assertEquals('text', $config['parameters']['data_column']);
        $this->assertEquals('4', $config['parameters']['string_length']);
        // volatile parameters must not get stored
        $this->assertArrayNotHasKey('foo', $config['parameters']);
        $this->assertArrayNotHasKey('baz', $config['parameters']);
    }

    public function testExecutorDefaultBucket()
    {
        $client = $this->client;
        if ($client->tableExists("in.c-docker-demo-whatever.sliced")) {
            $client->dropTable("in.c-docker-demo-whatever.sliced");
        }
        if ($client->bucketExists("in.c-docker-demo-whatever")) {
            $client->dropBucket("in.c-docker-demo-whatever");
        }

        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "default_bucket" => true
        ];

        $config = [];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $containerLog);
        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile(
                $container->getDataDir() . "/out/tables/sliced.csv",
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
            );
            $process = new Process('echo "Processed 1 rows."');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->setConfigurationId("whatever");
        $executor->setComponentId("docker-demo");
        $executor->initialize($container, $config, [], false);
        $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $executor->storeOutput($container, null);
        $this->assertTrue($client->tableExists("in.c-docker-demo-whatever.sliced"));

        if ($client->tableExists("in.c-docker-demo-whatever.sliced")) {
            $client->dropTable("in.c-docker-demo-whatever.sliced");
        }
        if ($client->bucketExists("in.c-docker-demo-whatever")) {
            $client->dropBucket("in.c-docker-demo-whatever");
        }
    }

    public function testExecutorDefaultBucketWithDot()
    {
        $client = $this->client;
        if ($client->tableExists("in.c-docker-demo-whatever.sliced")) {
            $client->dropTable("in.c-docker-demo-whatever.sliced");
        }
        if ($client->bucketExists("in.c-docker-demo-whatever")) {
            $client->dropBucket("in.c-docker-demo-whatever");
        }

        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "default_bucket" => true
        ];

        $config = [];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log, $containerLog);
        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile(
                $container->getDataDir() . "/out/tables/sliced.csv",
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
            );
            $process = new Process('echo "Processed 1 rows."');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->setConfigurationId("whatever");
        $executor->setComponentId("keboola.docker-demo");
        $executor->initialize($container, $config, [], false);
        $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $executor->storeOutput($container, null);
        $this->assertTrue($client->tableExists("in.c-keboola-docker-demo-whatever.sliced"));

        if ($client->tableExists("in.c-keboola-docker-demo-whatever.sliced")) {
            $client->dropTable("in.c-keboola-docker-demo-whatever.sliced");
        }
        if ($client->bucketExists("in.c-keboola-docker-demo-whatever")) {
            $client->dropBucket("in.c-keboola-docker-demo-whatever");
        }
    }

    public function testExecutorImageParametersEncrypt()
    {
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));
        $encrypted = $encryptor->encrypt('someString');

        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-config-encrypt-verify"
            ],
            "configuration_format" => "yaml",
            "image_parameters" => [
                "foo" => "bar",
                "baz" => [
                    "lily" => "pond"
                ],
                "#encrypted" => $encrypted
            ]
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog ->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log, $containerLog);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, [], [], false);
        $configFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'config.yml';
        $this->assertFileExists($configFile);
        $config = Yaml::parse($configFile);
        $this->assertEquals('bar', $config['image_parameters']['foo']);
        $this->assertEquals('pond', $config['image_parameters']['baz']['lily']);
        $this->assertEquals('someString', $config['image_parameters']['#encrypted']);
    }

    public function testExecutorImageParametersNoEncrypt()
    {
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));
        $encrypted = $encryptor->encrypt('someString');

        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-config-encrypt-verify"
            ],
            "configuration_format" => "yaml",
            "image_parameters" => [
                "foo" => "bar",
                "baz" => [
                    "lily" => "pond"
                ],
                "#encrypted" => $encrypted
            ]
        ];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log, $containerLog);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, [], [], true);
        $configFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'config.yml';
        $this->assertFileExists($configFile);
        $config = Yaml::parse($configFile);
        $this->assertEquals('bar', $config['image_parameters']['foo']);
        $this->assertEquals('pond', $config['image_parameters']['baz']['lily']);
        $this->assertEquals($encrypted, $config['image_parameters']['#encrypted']);
    }

    public function testGetSanitizedComponentId()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);

        $executor->setComponentId("keboola.ex-generic");
        $this->assertEquals("keboola-ex-generic", $executor->getSanitizedComponentId());

        $executor->setComponentId("ex-generic");
        $this->assertEquals("ex-generic", $executor->getSanitizedComponentId());

        $executor->setComponentId("keboola.ex.generic");
        $this->assertEquals("keboola-ex-generic", $executor->getSanitizedComponentId());
    }
}
