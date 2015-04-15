<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Executor;
use Keboola\DockerBundle\Docker\Image;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

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
    }

    public function tearDown()
    {
        // Delete local files
        $this->temp = null;

        // Delete tables
        foreach ($this->client->listTables("in.c-docker-test") as $table) {
            $this->client->dropTable($table["id"]);
        }

        $listFiles = new ListFilesOptions();
        $listFiles->setTags(['prepare']);
        $listFiles->setRunId($this->client->getRunId());
        foreach ($this->client->listFiles($listFiles) as $file) {
            $this->client->deleteFile($file['id']);
        }

        // Delete bucket
        $this->client->dropBucket("in.c-docker-test");
    }

    public function testExecutorRun()
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

        $log = new \Symfony\Bridge\Monolog\Logger("null");
        $log->pushHandler(new \Monolog\Handler\NullHandler());

        $image = Image::factory($imageConfig);

        $container = new \Keboola\DockerBundle\Tests\Docker\Mock\Container($image);

        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile($container->getDataDir() . "/out/tables/sliced.csv", "id,text,row_number\n1,test,1\n1,test,2\n1,test,3");
            $process = new Process('echo "Processed 1 rows."');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $executor = new Executor($this->client, $log);
        $executor->setTmpFolder($this->tmpDir);
        $executor->initialize($container, $config);
        $process = $executor->run($container, $config);
        $this->assertContains("Processed 1 rows.", trim($process->getOutput()));
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

        $log = new \Symfony\Bridge\Monolog\Logger("null");
        $log->pushHandler(new \Monolog\Handler\NullHandler());

        $image = Image::factory($imageConfig);

        $container = new \Keboola\DockerBundle\Tests\Docker\Mock\Container($image);

        $callback = function () use ($container) {
            $fs = new Filesystem();
            $fs->dumpFile($container->getDataDir() . "/out/tables/sliced.csv", "id,text,row_number\n1,test,1\n1,test,2\n1,test,3");
            $process = new Process('sleep 2 && echo "Processed 1 rows."');
            $process->setTimeout($container->getImage()->getProcessTimeout());
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $executor = new Executor($this->client, $log);
        $executor->setTmpFolder($this->tmpDir);
        try {
            $executor->initialize($container, $config);
            $executor->run($container, $config);
            $this->fail("Timeouted process should raise exception.");
        } catch (ProcessTimedOutException $e) {
            $this->assertContains('exceeded the timeout', $e->getMessage());
        }
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

        $log = new \Symfony\Bridge\Monolog\Logger("null");
        $log->pushHandler(new \Monolog\Handler\NullHandler());

        $image = Image::factory($imageConfig);

        $container = new \Keboola\DockerBundle\Tests\Docker\Mock\Container($image);

        $callback = function () use ($container) {
            $process = new Process('sleep 1');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $executor = new Executor($this->client, $log);
        $executor->setTmpFolder($this->tmpDir);
        $executor->initialize($container, $config);
        $executor->run($container, $config);
        $ret = $container->getRunCommand('test');
        $this->assertContains('KBC_TOKEN', $ret);
        $this->assertContains(STORAGE_API_TOKEN, $ret);
    }


    public function testExecutorPrepare()
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

        $log = new \Symfony\Bridge\Monolog\Logger("null");
        $log->pushHandler(new \Monolog\Handler\NullHandler());

        $image = Image::factory($imageConfig);

        $container = new \Keboola\DockerBundle\Tests\Docker\Mock\Container($image);

        $executor = new Executor($this->client, $log);
        $executor->setTmpFolder($this->tmpDir);
        $executor->initialize($container, $config);
        $executor->prepare($container);
        $this->assertFileExists(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'zip' . DIRECTORY_SEPARATOR . 'dataDirectory.zip'
        );
        $listFiles = new ListFilesOptions();
        $listFiles->setTags(['prepare']);
        $listFiles->setRunId($this->client->getRunId());
        $files = $this->client->listFiles($listFiles);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('dataDirectory.zip', $files[0]['name']));
    }
}
