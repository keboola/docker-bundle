<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Metadata;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderMetadataTestTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

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

    public function testDefaultSystemMetadata()
    {
        if ($this->client->bucketExists('in.c-docker-demo-whatever')) {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        }
        $metadataApi = new Metadata($this->client);

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

        $bucketMetadata = $metadataApi->listBucketMetadata('in.c-docker-demo-whatever');
        $this->assertCount(2, $bucketMetadata);
        foreach ($bucketMetadata as $bmd) {
            $this->assertEquals("system", $bmd['provider']);
            if ($bmd['key'] === "KBC.createdBy.component.id") {
                $this->assertEquals("docker-demo", $bmd['value']);
            } else {
                $this->assertEquals("KBC.createdBy.configuration.id", $bmd['key']);
                $this->assertEquals("whatever", $bmd['value']);
            }
        }
        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $this->assertCount(2, $tableMetadata);
        foreach ($bucketMetadata as $bmd) {
            $this->assertEquals("system", $bmd['provider']);
            if ($bmd['key'] === "KBC.createdBy.component.id") {
                $this->assertEquals("docker-demo", $bmd['value']);
            } else {
                $this->assertEquals("KBC.createdBy.configuration.id", $bmd['key']);
                $this->assertEquals("whatever", $bmd['value']);
            }
        }

        // let's run the data loader again.
        // This time the tables should receive "update" metadata
        $dataLoader->storeOutput();

        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $this->assertCount(4, $tableMetadata);
        foreach ($tableMetadata as $tmd) {
            $this->assertEquals("system", $tmd['provider']);
            if (stristr($tmd['key'], "updated")) {
                if ($tmd['key'] === "KBC.lastUpdatedBy.component.id") {
                    $this->assertEquals("docker-demo", $tmd['value']);
                } else {
                    $this->assertEquals("KBC.lastUpdatedBy.configuration.id", $tmd['key']);
                    $this->assertEquals("whatever", $tmd['value']);
                }
            }
        }
    }

    public function testExecutorManifestMetadata()
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
                        "metadata" => [
                            [
                                "key" => "table.key.one",
                                "value" => "table value one"
                            ],
                            [
                                "key" => "table.key.two",
                                "value" => "table value two"
                            ]
                        ],
                        "columnMetadata" => [
                            "id" => [
                                [
                                    "key" => "column.key.one",
                                    "value" => "column value one id"
                                ],
                                [
                                    "key" => "column.key.two",
                                    "value" => "column value two id"
                                ]
                            ],
                            "text" => [
                                [
                                    "key" => "column.key.one",
                                    "value" => "column value one text"
                                ],
                                [
                                    "key" => "column.key.two",
                                    "value" => "column value two text"
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $dataLoader = new DataLoader(
            $this->client,
            $log,
            $data->getDataDir(),
            $config,
            $this->getDefaultBucketComponent(),
            "whatever"
        );
        $dataLoader->storeOutput();

        $metadataApi = new Metadata($this->client);
        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');

        $this->assertCount(4, $tableMetadata); // 2 system provided + 2 manifest provided
        foreach ($tableMetadata as $tmd) {
            $this->assertArrayHasKey("key", $tmd);
            $this->assertArrayHasKey("value", $tmd);
            if ($tmd['provider'] === "system") {
                if ($tmd['key'] === "KBC.createdBy.component.id") {
                    $this->assertEquals("docker-demo", $tmd["value"]);
                } else {
                    $this->assertEquals("KBC.createdBy.configuration.id", $tmd["key"]);
                    $this->assertEquals("whatever", $tmd["value"]);
                }
            } else {
                $this->assertEquals("docker-demo", $tmd["provider"]);
                if ($tmd['key'] === "table.key.one") {
                    $this->assertEquals("table value one", $tmd["value"]);
                } else {
                    $this->assertEquals("table value two", $tmd["value"]);
                }
            }
        }

        $idColMetadata = $metadataApi->listColumnMetadata('in.c-docker-demo-whatever.sliced.id');

        $this->assertCount(2, $idColMetadata);
        foreach ($idColMetadata as $tmd) {
            $this->assertArrayHasKey("key", $tmd);
            $this->assertArrayHasKey("value", $tmd);
            $this->assertEquals("docker-demo", $tmd["provider"]);
            if ($tmd['key'] === "column.key.one") {
                $this->assertEquals("column value one id", $tmd["value"]);
            } else {
                $this->assertEquals("column.key.two", $tmd["key"]);
                $this->assertEquals("column value two id", $tmd["value"]);
            }
        }

        if ($this->client->bucketExists('in.c-docker-demo-whatever')) {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        }
    }

}