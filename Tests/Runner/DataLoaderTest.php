<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
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
        $data = new DataDirectory($temp->getTmpFolder());
        $data->createDataDir();

        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), [], 'in.c-docker-demo-whatever', 'json');
        $dataLoader->storeOutput();

        $this->assertTrue($this->client->tableExists('in.c-docker-demo-whatever.sliced'));

        if ($this->client->bucketExists('in.c-docker-demo-whatever')) {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
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
        $data = new DataDirectory($temp->getTmpFolder());
        $data->createDataDir();
        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, '', 'json');
        try {
            $dataLoader->storeOutput();
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }

    public function testExecutorInvalidInputMapping()
    {
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
        $data = new DataDirectory($temp->getTmpFolder());
        $data->createDataDir();
        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, '', 'json');
        try {
            $dataLoader->loadInputData();
            $this->fail("Invalid configuration must raise UserException.");
        } catch (UserException $e) {
        }
    }

    public function testExecutorInvalidInputMapping2()
    {
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
        $data = new DataDirectory($temp->getTmpFolder());
        $data->createDataDir();
        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, '', 'json');
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
        $data = new DataDirectory($temp->getTmpFolder());
        $data->createDataDir();

        $fs = new Filesystem();
        $filePath = $data->getDataDir() . '/in/tables/test.csv';
        $fs->dumpFile(
            $filePath,
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $this->client->createTable('in.c-docker-test', 'test', new CsvFile($filePath));

        $dataLoader = new DataLoader($this->client, $log, $data->getDataDir(), $config, '', 'json', 's3');
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
        if ($manifest["s3"]["isSliced"]) {
            $this->assertContains(".manifest", $manifest["s3"]["key"]);
        }
    }
}
