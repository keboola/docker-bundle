<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Executor;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Tests\Docker\Mock\Container as MockContainer;
use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
use Keboola\OAuthV2Api\Credentials;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
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

        $this->client = new Client(array("token" => STORAGE_API_TOKEN));

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
            $csv->writeRow(array("id", "text"));
            $csv->writeRow(array("test", "testtesttest"));
            $this->client->createTableAsync("in.c-docker-test", "test", $csv);
        }

        $listFiles = new ListFilesOptions();
        $listFiles->setTags(['sandbox']);
        $listFiles->setRunId($this->client->getRunId());
        foreach ($this->client->listFiles($listFiles) as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    public function testDockerHubExecutorRun()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "streaming_logs" => false
        );

        $config = array(
            "storage" => array(
                "input" => array(
                    "tables" => array(
                        array(
                            "source" => "in.c-docker-test.test"
                        )
                    )
                ),
                "output" => array(
                    "tables" => array(
                        array(
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        )
                    )
                )
            ),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

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
        $message = $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertContains("Processed 1 rows.", trim($message));
        $ret = $container->getRunCommand('test');
        // make sure that the token is NOT forwarded by default
        $this->assertNotContains(STORAGE_API_TOKEN, $ret);
    }


    public function testQuayIOExecutorRun()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "quayio",
                "uri" => "keboola/docker-demo-app"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "streaming_logs" => false
        );

        $config = array(
            "storage" => array(
                "input" => array(
                    "tables" => array(
                        array(
                            "source" => "in.c-docker-test.test"
                        )
                    )
                ),
                "output" => array(
                    "tables" => array(
                        array(
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        )
                    )
                )
            ),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

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
        $message = $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertContains("Processed 1 rows.", trim($message));
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

        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub-private",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => array(
                    "email" => DOCKERHUB_PRIVATE_EMAIL,
                    "#password" => $encryptor->encrypt(DOCKERHUB_PRIVATE_PASSWORD),
                    "username" => DOCKERHUB_PRIVATE_USERNAME
                )
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "streaming_logs" => false
        );

        $config = array(
            "storage" => array(
                "input" => array(
                    "tables" => array(
                        array(
                            "source" => "in.c-docker-test.test"
                        )
                    )
                ),
                "output" => array(
                    "tables" => array(
                        array(
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        )
                    )
                )
            ),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

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
        $message = $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertContains("Processed 1 rows.", trim($message));
        $ret = $container->getRunCommand('test');
        // make sure that the token is NOT forwarded by default
        $this->assertNotContains(STORAGE_API_TOKEN, $ret);
    }


    public function testExecutorRunTimeout()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "process_timeout" => 1
        );

        $config = array(
            "storage" => array(),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile(
                $container->getDataDir() . "/out/tables/sliced.csv",
                "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
            );
            $process = new Process('sleep 2 && echo "Processed 1 rows."');
            $process->setTimeout($container->getImage()->getProcessTimeout());
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        try {
            $executor->initialize($container, $config, [], false);
            $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
            $this->fail("Timeouted process should raise exception.");
        } catch (ProcessTimedOutException $e) {
            $this->assertContains('exceeded the timeout', $e->getMessage());
        }
    }

    public function testExecutorEnvs()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "process_timeout" => 1
        );

        $config = array(
            "storage" => array(),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $process = new Process('sleep 1');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $executor->run($container, "testsuite", $this->client->verifyToken(), 'testConfigurationId');
        $ret = $container->getRunCommand('test');
        $this->assertContains('KBC_PROJECTID', $ret);
        $this->assertContains('KBC_CONFIGID', $ret);
        $this->assertContains('testConfigurationId', $ret);
        $this->assertNotContains('KBC_TOKEN=', $ret);
        $this->assertNotContains(STORAGE_API_TOKEN, $ret);
        $this->assertNotContains('KBC_PROJECTNAME', $ret);
        $this->assertNotContains('KBC_TOKENID', $ret);
        $this->assertNotContains('KBC_TOKENDESC', $ret);
    }

    public function testExecutorForwardToken()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "process_timeout" => 1,
            "forward_token" => true
        );

        $config = array(
            "storage" => array(),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $process = new Process('sleep 1');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $ret = $container->getRunCommand('test');
        $this->assertContains('KBC_TOKEN', $ret);
        $this->assertContains(STORAGE_API_TOKEN, $ret);
        $this->assertContains('KBC_PROJECTID', $ret);
        $this->assertContains('KBC_CONFIGID', $ret);
        $this->assertNotContains('KBC_PROJECTNAME', $ret);
        $this->assertNotContains('KBC_TOKENID', $ret);
        $this->assertNotContains('KBC_TOKENDESC', $ret);
    }

    public function testExecutorForwardTokenDetails()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "process_timeout" => 1,
            "forward_token_details" => true
        );

        $config = array(
            "storage" => array(),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $process = new Process('sleep 1');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $tokenInfo = $this->client->verifyToken();
        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $executor->run($container, "testsuite", $tokenInfo, 'test-config');
        $ret = $container->getRunCommand('test');
        $this->assertNotContains('KBC_TOKEN=', $ret);
        $this->assertContains('KBC_CONFIGID', $ret);
        $this->assertContains('KBC_PROJECTID', $ret);
        $this->assertContains('KBC_PROJECTNAME', $ret);
        $this->assertContains('KBC_TOKENID', $ret);
        $this->assertContains('KBC_TOKENDESC', $ret);

        $this->assertContains(strval($tokenInfo["owner"]["id"]), $ret);
        $this->assertContains(str_replace('"', '\"', $tokenInfo["owner"]["name"]), $ret);
        $this->assertContains(strval($tokenInfo["id"]), $ret);
        $this->assertContains(str_replace('"', '\"', $tokenInfo["description"]), $ret);
    }


    public function testExecutorSandbox()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $config = array(
            "storage" => array(
                "input" => array(
                    "tables" => array(
                        array(
                            "source" => "in.c-docker-test.test"
                        )
                    )
                ),
                "output" => array(
                    "tables" => array(
                        array(
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        )
                    )
                )
            ),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

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
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $config = array(
            "storage" => array(
                "input" => array(
                    "tables" => array(
                        array(
                            "source" => "in.c-docker-test.test"
                        )
                    )
                ),
                "output" => array(
                    "tables" => array(
                        array(
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out",
                            // erroneous lines
                            "primary_key" => "col1",
                            "incremental" => 1
                        )
                    )
                )
            ),
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

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
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $config = array(
            "storage" => array(
                "input" => array(
                    "tables" => array(
                        array(
                            "source" => "in.c-docker-test.test",
                            // erroneous lines
                            "foo" => "bar"
                        )
                    )
                ),
                "output" => array(
                    "tables" => array(
                        array(
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        )
                    )
                )
            ),
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

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
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $config = array(
            "storage" => array(
                "input" => array(
                    "tables" => array(
                        array(
                            "source" => "in.c-docker-test.test",
                            // erroneous lines
                            "columns" => array(
                                array(
                                    "value" => "id",
                                    "label" => "id"
                                ),
                                array(
                                    "value" => "col1",
                                    "label" => "col1"
                                )
                            )
                        )
                    )
                ),
                "output" => array(
                    "tables" => array(
                        array(
                            "source" => "sliced.csv",
                            "destination" => "in.c-docker-test.out"
                        )
                    )
                )
            ),
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
    }

    public function testExecutorStoreEmptyStateFile()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "process_timeout" => 1,
            "forward_token_details" => true
        );

        $config = array(
            "storage" => array(),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $process = new Process('sleep 1');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $this->assertFileExists($this->tmpDir . "/data/in/state.json");
        $this->assertEquals(
            new \stdclass(),
            json_decode(file_get_contents($this->tmpDir . "/data/in/state.json"), false)
        );
    }

    public function testExecutorStoreNonEmptyStateFile()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "process_timeout" => 1,
            "forward_token_details" => true
        );

        $config = array(
            "storage" => array(),
            "parameters" => array(
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            )
        );

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

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
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log);

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

        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "default_bucket" => true
        );

        $config = array();

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);
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

        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "default_bucket" => true
        );

        $config = array();

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);
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
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log);

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
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log);

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
    }

    public function testContainerMessageTrimmingStreamingOff()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "streaming_logs" => false
        ];

        $config = [];
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile($container->getDataDir() . "/in/files/text", str_repeat("Batman", 100000));

            $process = new Process('cat ' . $container->getDataDir() . '/in/files/text');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $message = $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertContains("BatmanBatman", trim($message));
        $this->assertContains('...', $message);
        $this->assertEquals(64005, strlen($message));
    }

    public function testContainerMessageTrimmingStreamingOn()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "streaming_logs" => true
        ];

        $config = [];
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);
        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile($container->getDataDir() . "/in/files/text", str_repeat("Batman", 100000));

            $process = new Process('cat ' . $container->getDataDir() . '/in/files/text');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClient = new Credentials($this->client->getTokenString());
        $executor = new Executor($this->client, $log, $oauthClient, $this->tmpDir);
        $executor->initialize($container, $config, [], false);
        $message = $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $this->assertEquals("Docker container processing finished.", trim($message));
    }

    public function testOauthConfigDecrypt()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "default_bucket" => true
        );

        $config = ["authorization" => ["oauth_api" => ["id" => "whatever"]]];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $oauthClientStub = $this->getMockBuilder("\\Keboola\\OAuthV2Api\\Credentials")
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse));

        $executor = new Executor($this->client, $log, $oauthClientStub, $this->tmpDir);
        $executor->setConfigurationId("whatever");
        $executor->setComponentId("keboola.docker-demo");
        $executor->initialize($container, $config, [], false);

        $this->assertEquals(
            $credentials,
            json_decode(file_get_contents($container->getDataDir() . "/config.json"), true)["authorization"]["oauth_api"]["credentials"]
        );
    }

    public function testOauthConfigDecryptSandboxed()
    {
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "default_bucket" => true
        );

        $config = ["authorization" => ["oauth_api" => ["id" => "whatever"]]];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $oauthClientStub = $this->getMockBuilder("\\Keboola\\OAuthV2Api\\Credentials")
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse));

        $executor = new Executor($this->client, $log, $oauthClientStub, $this->tmpDir);
        $executor->setConfigurationId("whatever");
        $executor->setComponentId("keboola.docker-demo");
        $executor->initialize($container, $config, [], true);

        $this->assertEquals(
            $oauthResponse,
            json_decode(file_get_contents($container->getDataDir() . "/config.json"), true)["authorization"]["oauth_api"]["credentials"]
        );
    }

    public function testOauthConfigDecryptAndExecute()
    {
        $client = $this->client;
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "streaming_logs" => false
        );

        $config = ["authorization" => ["oauth_api" => ["id" => "test-credentials-45"]]];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $configFile = json_decode(file_get_contents($container->getDataDir() . "/config.json"), true);
            $process = new Process('echo ' . escapeshellarg(json_encode($configFile)) . '');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClientStub = $this->getMockBuilder("\\Keboola\\OAuthV2Api\\Credentials")
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will($this->returnValue($oauthResponse));

        $executor = new Executor($this->client, $log, $oauthClientStub, $this->tmpDir);
        $executor->setConfigurationId("test-credentials-45");
        $executor->setComponentId("keboola.docker-demo");
        $executor->initialize($container, $config, [], false);

        $message = $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $expectedConfigFile = [
            "authorization" => [
                "oauth_api" => [
                    "id" => "test-credentials-45",
                    "credentials" => $credentials
                ]
            ],
            "image_parameters" => []
        ];
        $this->assertEquals(json_encode($expectedConfigFile), trim($message));
    }

    public function testOauthConfigExecuteSandboxed()
    {
        $client = $this->client;
        $imageConfig = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "streaming_logs" => false
        );

        $config = ["authorization" => ["oauth_api" => ["id" => "test-credentials-45"]]];

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new MockContainer($image, $log);

        $callback = function () use ($container) {
            $configFile = json_decode(file_get_contents($container->getDataDir() . "/config.json"), true);
            $process = new Process('echo ' . escapeshellarg(json_encode($configFile)) . '');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $oauthClientStub = $this->getMockBuilder("\\Keboola\\OAuthV2Api\\Credentials")
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will($this->returnValue($oauthResponse));

        $executor = new Executor($this->client, $log, $oauthClientStub, $this->tmpDir);
        $executor->setConfigurationId("test-credentials-45");
        $executor->setComponentId("keboola.docker-demo");
        $executor->initialize($container, $config, [], true);

        $message = $executor->run($container, "testsuite", $this->client->verifyToken(), 'test-config');
        $expectedConfigFile = [
            "authorization" => [
                "oauth_api" => [
                    "id" => "test-credentials-45",
                    "credentials" => $oauthResponse
                ]
            ],
            "image_parameters" => []
        ];
        $this->assertEquals(json_encode($expectedConfigFile), trim($message));
    }
}
