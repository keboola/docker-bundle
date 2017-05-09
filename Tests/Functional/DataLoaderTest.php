<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    private function getS3StagingComponent()
    {
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],
                "staging-storage" => [
                    "input" => "s3"
                ]
            ]
        ]);
    }

    private function getNoDefaultBucketComponent()
    {
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],

            ]
        ]);
    }

    private function getDefaultBucketComponent()
    {
        // use the docker-demo component for testing
        return new Component([
            'id' => 'docker-demo',
            'data' => [
                "definition" => [
                    "type" => "dockerhub",
                    "uri" => "keboola/docker-demo",
                    "tag" => "master"
                ],
                "default_bucket" => true
            ]
        ]);
    }

    public function setUp()
    {
        parent::setUp();

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
    }

    public function testExecutorDefaultBucket()
    {
        if ($this->client->bucketExists('in.c-docker-demo-whatever')) {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        }

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), $log);
        $data->createDataDir();

        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader(
            $this->client,
            $log,
            $data->getDataDir(),
            [],
            $this->getDefaultBucketComponent(),
            "whatever"
        );
        $dataLoader->storeOutput();

        $this->assertTrue($this->client->tableExists('in.c-docker-demo-whatever.sliced'));

        if ($this->client->bucketExists('in.c-docker-demo-whatever')) {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        }
    }

    public function testNoConfigDefaultBucketException()
    {
        try {
            $log = new Logger('null');
            $log->pushHandler(new NullHandler());

            $temp = new Temp();
            $data = new DataDirectory($temp->getTmpFolder(), $log);
            $data->createDataDir();

            new DataLoader(
                $this->client,
                $log,
                $data->getDataDir(),
                [],
                $this->getDefaultBucketComponent()
            );
            $this->fail("ConfigId is required for defaultBucket=true component data setting");
        } catch (UserException $e) {
            $this->assertStringStartsWith("Configuration ID not set", $e->getMessage());
        }
    }

    public function testExecutorInvalidOutputMapping()
    {
        $config = [
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
        ];

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), $log);
        $data->createDataDir();
        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, $this->getNoDefaultBucketComponent());
        try {
            $dataLoader->storeOutput();
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }

    public function testExecutorInvalidInputMapping()
    {
        $this->markTestSkipped("FIXME:  Array to string conversion isn't a UserException");
        $config = [
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
        ];

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), $log);
        $data->createDataDir();
        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, $this->getNoDefaultBucketComponent());
        try {
            $dataLoader->loadInputData();
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }

    public function testExecutorInvalidInputMapping2()
    {
        $this->markTestSkipped("FIXME:  Array to string conversion isn't a UserException");
        $config = [
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
        ];

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), $log);
        $data->createDataDir();
        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, $this->getNoDefaultBucketComponent());
        try {
            $dataLoader->loadInputData();
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }

    public function testLoadInputDataS3()
    {
        if ($this->client->bucketExists('in.c-docker-test')) {
            $this->client->dropBucket('in.c-docker-test', ['force' => true]);
        }
        $this->client->createBucket('docker-test', Client::STAGE_IN);

        $config = [
            "input" => [
                "tables" => [
                    [
                        "source" => "in.c-docker-test.test",
                    ]
                ]
            ]
        ];

        $log = new Logger('null');
        $log->pushHandler(new NullHandler());
        $temp = new Temp();
        $temp->setPreserveRunFolder(true);
        $data = new DataDirectory($temp->getTmpFolder(), $log);
        $data->createDataDir();

        $fs = new Filesystem();
        $filePath = $data->getDataDir() . '/in/tables/test.csv';
        $fs->dumpFile(
            $filePath,
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $this->client->createTable('in.c-docker-test', 'test', new CsvFile($filePath));

        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, $this->getS3StagingComponent());
        $dataLoader->loadInputData();

        $manifest = json_decode(
            file_get_contents($data->getDataDir() . '/in/tables/in.c-docker-test.test.manifest'),
            true
        );

        $this->assertS3info($manifest);
    }

    private function assertS3info($manifest)
    {
        $this->assertArrayHasKey("s3", $manifest);
        $this->assertArrayHasKey("isSliced", $manifest["s3"]);
        $this->assertArrayHasKey("region", $manifest["s3"]);
        $this->assertArrayHasKey("bucket", $manifest["s3"]);
        $this->assertArrayHasKey("key", $manifest["s3"]);
        $this->assertArrayHasKey("credentials", $manifest["s3"]);
        $this->assertArrayHasKey("access_key_id", $manifest["s3"]["credentials"]);
        $this->assertArrayHasKey("secret_access_key", $manifest["s3"]["credentials"]);
        $this->assertArrayHasKey("session_token", $manifest["s3"]["credentials"]);
        $this->assertContains(".gz", $manifest["s3"]["key"]);
        if ($manifest["s3"]["isSliced"]) {
            $this->assertContains("manifest", $manifest["s3"]["key"]);
        }
    }
}
