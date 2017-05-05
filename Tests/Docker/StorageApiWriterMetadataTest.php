<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Metadata;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class StorageApiWriterMetadataTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Temp
     */
    private $tmp;

    public function setUp()
    {
        // Create folders
        $this->tmp = new Temp();
        $this->tmp->initRunFolder();
        $root = $this->tmp->getTmpFolder();
        $fs = new Filesystem();
        $fs->mkdir($root . DIRECTORY_SEPARATOR . 'upload');
        $fs->mkdir($root . DIRECTORY_SEPARATOR . 'download');

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
    }

    public function tearDown()
    {
        if ($this->client->bucketExists('in.c-docker-test')) {
            $this->client->dropBucket('in.c-docker-test', ['force' => true]);
        }
        // Delete local files
        $this->tmp = null;
    }

    public function testMetadataWritingTest()
    {
        if (!$this->client->bucketExists('in.c-docker-test.table1')) {
            $this->client->createBucket('docker-test', "in");
        }

        $root = $this->tmp->getTmpFolder();
        file_put_contents($root . "/upload/table1.csv", "\"Id\",\"Name\"\n\"test\",\"test\"\n\"aabb\",\"ccdd\"\n");

        $config = [
                    "mapping" => [
                        [
                            "source" => "table1.csv",
                            "destination" => "in.c-docker-test.table1",
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
                                "Id" => [
                                    [
                                        "key" => "column.key.one",
                                        "value" => "column value one id"
                                    ],
                                    [
                                        "key" => "column.key.two",
                                        "value" => "column value two id"
                                    ]
                                ],
                                "Name" => [
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
                    ],
                    // This gets added by the DataLoader
                    "provider" => [
                        "componentId" => "testComponent",
                        "configurationId" => "metadata-write-test"
                    ]
                ];

        $writer = new Writer($this->client, (new Logger("null"))->pushHandler(new NullHandler()));

        $writer->uploadTables($root . "/upload", $config, []);

        $metadataApi = new Metadata($this->client);

        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-test.table1');
        $this->assertCount(4, $tableMetadata); // 2 system provided + 2 manifest provided
        foreach ($tableMetadata as $tmd) {
            $this->assertArrayHasKey("key", $tmd);
            $this->assertArrayHasKey("value", $tmd);
            if ($tmd['provider'] === "system") {
                if ($tmd['key'] === "KBC.createdBy.component.id") {
                    $this->assertEquals("testComponent", $tmd["value"]);
                } else {
                    $this->assertEquals("KBC.createdBy.configuration.id", $tmd["key"]);
                    $this->assertEquals("metadata-write-test", $tmd["value"]);
                }
            } else {
                $this->assertEquals("testComponent", $tmd["provider"]);
                if ($tmd['key'] === "table.key.one") {
                    $this->assertEquals("table value one", $tmd["value"]);
                } else {
                    $this->assertEquals("table value two", $tmd["value"]);
                }
            }
        }

        $idColMetadata = $metadataApi->listColumnMetadata('in.c-docker-test.table1.Id');
        $this->assertCount(2, $idColMetadata);
        foreach ($idColMetadata as $tmd) {
            $this->assertArrayHasKey("key", $tmd);
            $this->assertArrayHasKey("value", $tmd);
            $this->assertEquals("testComponent", $tmd["provider"]);
            if ($tmd['key'] === "column.key.one") {
                $this->assertEquals("column value one id", $tmd["value"]);
            } else {
                $this->assertEquals("column.key.two", $tmd["key"]);
                $this->assertEquals("column value two id", $tmd["value"]);
            }
        }

        // check metadata update
        $writer->uploadTables($root . "/upload", $config, []);
        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-test.table1');
        $this->assertCount(6, $tableMetadata);
        foreach ($tableMetadata as $tmd) {
            if (stristr($tmd['key'], "updated")) {
                $this->assertEquals("system", $tmd['provider']);
                if ($tmd['key'] === "KBC.lastUpdatedBy.component.id") {
                    $this->assertEquals("testComponent", $tmd['value']);
                } else {
                    $this->assertEquals("KBC.lastUpdatedBy.configuration.id", $tmd['key']);
                    $this->assertEquals("metadata-write-test", $tmd['value']);
                }
            }
        }
    }
}
