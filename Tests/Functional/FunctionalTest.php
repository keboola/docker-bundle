<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Job\Executor;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class FunctionalTests extends \PHPUnit_Framework_TestCase
{

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Temp
     */
    private $temp;


    public function setUp()
    {
        $this->client = new Client(["token" => STORAGE_API_TOKEN]);
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();

        if ($this->client->bucketExists("out.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("out.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("out.c-docker-test");
        }

    }


    public function tearDown()
    {
        if ($this->client->bucketExists("in.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("in.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("in.c-docker-test");
        }
        if ($this->client->bucketExists("out.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("out.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("out.c-docker-test");
        }
        $fs = new Filesystem();
        $fs->remove($this->temp->getTmpFolder());
    }


    public function testRDocker()
    {
        $data = [
            'params' => [
                'component' => 'docker-r',
                'mode' => 'run',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ]
                    ]
                ]
            ]
        ];
        $job = new Job($data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            $csv->writeRow(['price', '100', '1000']);
            $csv->writeRow(['size', 'small', 'big']);
            $csv->writeRow(['age', 'low', 'high']);
            $csv->writeRow(['kindness', 'no', 'yes']);
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $jobExecutor = new Executor($log, $this->temp);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        $csvData = $this->client->exportTable('out.c-docker-test.transposed');
        $data = Client::parseCsv($csvData);
        $this->assertEquals(2, count($data));
        $this->assertArrayHasKey('column', $data[0]);
        $this->assertArrayHasKey('price', $data[0]);
        $this->assertArrayHasKey('size', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('kindness', $data[0]);
    }


    public function testSandbox()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("sandbox"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        $data = [
            'params' => [
                'component' => 'docker-r',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ],
                    ]
                ],
                'mode' => 'sandbox',
                'format' => 'yaml'
            ]
        ];
        $job = new Job($data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
            $fs = new Filesystem();
            unset($csv);
            $fs->remove($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $jobExecutor = new Executor($log, $this->temp);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['sandbox']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('datadirectory.zip', $files[0]['name']));
        $this->assertLessThan(4000, $files[0]['sizeBytes']);
    }


    public function testInput()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("input"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        $data = [
            'params' => [
                'component' => 'docker-r',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ],
                    ]
                ],
                'mode' => 'input',
                'format' => 'yaml'
            ]
        ];
        $job = new Job($data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $jobExecutor = new Executor($log, $this->temp);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['input']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('datadirectory.zip', $files[0]['name']));
        $this->assertGreaterThan(6000, $files[0]['sizeBytes']);
    }


    public function testDryRun()
    {
        // Delete file uploads
        $options = new ListFilesOptions();
        $options->setTags(array("dry-run"));
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        $data = [
            'params' => [
                'component' => 'docker-r',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ],
                    ]
                ],
                'mode' => 'dry-run',
                'format' => 'yaml'
            ]
        ];
        $job = new Job($data);

        // Create buckets
        if (!$this->client->bucketExists("in.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        }
        if (!$this->client->bucketExists("out.c-docker-test")) {
            $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");
        }

        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $jobExecutor = new Executor($log, $this->temp);
        $jobExecutor->setStorageApi($this->client);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['dry-run']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('datadirectory.zip', $files[0]['name']));
        $this->assertGreaterThan(6300, $files[0]['sizeBytes']);
    }


    public function testHelloWorld()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "hello-world"
            )
        );
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        $container->setId("hello-world");
        $container->setDataDir("/tmp");
        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Hello from Docker", trim($process->getOutput()));
    }


    /**
     * @expectedException \Keboola\Syrup\Exception\ApplicationException
     */
    public function testException()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "hello-world"
            )
        );
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        $container->run();
    }
}
