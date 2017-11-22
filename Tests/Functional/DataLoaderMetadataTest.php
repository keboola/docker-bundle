<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Metadata;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderMetadataTestTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * Transform metadata into a key-value array
     * @param $metadata
     * @return array
     */
    private function getMetadataValues($metadata)
    {
        $result = [];
        foreach ($metadata as $item) {
            $result[$item['provider']][$item['key']] = $item['value'];
        }
        return $result;
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

        try {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }

    public function tearDown()
    {
        try {
            $this->client->dropBucket('in.c-docker-demo-whatever', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }
    }

    public function testDefaultSystemMetadata()
    {
        $metadataApi = new Metadata($this->client);
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createDataDir();

        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            [],
            $this->getDefaultBucketComponent(),
            "whatever"
        );
        $dataLoader->storeOutput();

        $bucketMetadata = $metadataApi->listBucketMetadata('in.c-docker-demo-whatever');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'whatever'
            ]
        ];
        $this->assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));

        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'whatever'
            ]
        ];
        $this->assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        // let's run the data loader again.
        // This time the tables should receive "update" metadata
        $dataLoader->storeOutput();

        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.component.id'] = 'docker-demo';
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.configuration.id'] = 'whatever';
        $this->assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));
    }


    public function testDefaultSystemConfigRowMetadata()
    {
        $metadataApi = new Metadata($this->client);
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createDataDir();

        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            [],
            $this->getDefaultBucketComponent(),
            "whatever",
            "whateverRow"
        );
        $dataLoader->storeOutput();

        $bucketMetadata = $metadataApi->listBucketMetadata('in.c-docker-demo-whatever');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'whatever',
                'KBC.createdBy.configurationRow.id' => 'whateverRow'
            ]
        ];
        $this->assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));

        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'whatever',
                'KBC.createdBy.configurationRow.id' => 'whateverRow'
            ]
        ];
        $this->assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        // let's run the data loader again.
        // This time the tables should receive "update" metadata
        $dataLoader->storeOutput();

        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.component.id'] = 'docker-demo';
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.configuration.id'] = 'whatever';
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.configurationRow.id'] = 'whateverRow';
        $this->assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));
    }

    public function testExecutorConfigMetadata()
    {
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), new NullLogger());
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
                        "column_metadata" => [
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
            new NullLogger(),
            $data->getDataDir(),
            $config,
            $this->getDefaultBucketComponent(),
            "whatever"
        );
        $dataLoader->storeOutput();

        $metadataApi = new Metadata($this->client);
        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'whatever',
                'KBC.createdBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value one',
                'table.key.two' => 'table value two',
            ],
        ];
        $this->assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $metadataApi->listColumnMetadata('in.c-docker-demo-whatever.sliced.id');
        $expectedColumnMetadata = [
            'docker-demo' => [
                'column.key.one' => 'column value one id',
                'column.key.two' => 'column value two id',
            ],
        ];
        $this->assertEquals($expectedColumnMetadata, $this->getMetadataValues($idColMetadata));
    }

    public function testExecutorManifestMetadata()
    {
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createDataDir();

        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $manifest = '
            {
                "destination": "in.c-docker-demo-whatever.sliced",
                "metadata": [{
                        "key": "table.key.one",
                        "value": "table value one"
                    },
                    {
                        "key": "table.key.two",
                        "value": "table value two"
                    }
                ],
                "column_metadata": {
                    "id": [{
                            "key": "column.key.one",
                            "value": "column value one id"
                        },
                        {
                            "key": "column.key.two",
                            "value": "column value two id"
                        }
                    ],
                    "text": [{
                            "key": "column.key.one",
                            "value": "column value one text"
                        },
                        {
                            "key": "column.key.two",
                            "value": "column value two text"
                        }
                    ]
                }
            }        
        ';
        $fs->dumpFile($data->getDataDir() . '/out/tables/sliced.csv.manifest', $manifest);

        $config = [];
        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            $config,
            $this->getDefaultBucketComponent(),
            "whatever"
        );
        $dataLoader->storeOutput();

        $metadataApi = new Metadata($this->client);
        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'whatever',
                'KBC.createdBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value one',
                'table.key.two' => 'table value two',
            ],
        ];
        $this->assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $metadataApi->listColumnMetadata('in.c-docker-demo-whatever.sliced.id');
        $expectedColumnMetadata = [
            'docker-demo' => [
                'column.key.one' => 'column value one id',
                'column.key.two' => 'column value two id',
            ],
        ];
        $this->assertEquals($expectedColumnMetadata, $this->getMetadataValues($idColMetadata));
    }

    public function testExecutorManifestMetadataCombined()
    {
        $temp = new Temp();
        $data = new DataDirectory($temp->getTmpFolder(), new NullLogger());
        $data->createDataDir();

        $fs = new Filesystem();
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $fs->dumpFile(
            $data->getDataDir() . '/out/tables/sliced.csv.manifest',
            '{"metadata":[{"key":"table.key.one","value":"table value one"},'.
            '{"key":"table.key.two","value":"table value two"}],"column_metadata":{"id":['.
            '{"key":"column.key.one","value":"column value one id"},'.
            '{"key":"column.key.two","value":"column value two id"}],'.
            '"text":[{"key":"column.key.one","value":"column value one text"},'.
            '{"key":"column.key.two","value":"column value two text"}]}}'
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
                        "destination" => "in.c-docker-demo-whatever.sliced",
                        "metadata" => [
                            [
                                "key" => "table.key.one",
                                "value" => "table value three"
                            ],
                            [
                                "key" => "table.key.two",
                                "value" => "table value four"
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $dataLoader = new DataLoader(
            $this->client,
            new NullLogger(),
            $data->getDataDir(),
            $config,
            $this->getDefaultBucketComponent(),
            "whatever"
        );
        $dataLoader->storeOutput();

        $metadataApi = new Metadata($this->client);
        $tableMetadata = $metadataApi->listTableMetadata('in.c-docker-demo-whatever.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'whatever',
                'KBC.createdBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value three',
                'table.key.two' => 'table value four',
            ],
        ];
        $this->assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $metadataApi->listColumnMetadata('in.c-docker-demo-whatever.sliced.id');
        $expectedColumnMetadata = [];
        $this->assertEquals($expectedColumnMetadata, $this->getMetadataValues($idColMetadata));
    }
}
