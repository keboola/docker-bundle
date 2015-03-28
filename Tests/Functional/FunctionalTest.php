<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Job\Executor;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;

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
    }


    public function testRDocker()
    {
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

        $log = new \Symfony\Bridge\Monolog\Logger("null");
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


    public function testDryRun()
    {
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
                'dryRun' => 1
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

        $log = new \Symfony\Bridge\Monolog\Logger("null");
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
        $listOptions->setTags(['dryRun']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('datadirectory.zip', $files[0]['name']));
    }
}
