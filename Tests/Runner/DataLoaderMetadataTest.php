<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderMetadataTest extends BaseDataLoaderTest
{
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

    public function testDefaultSystemMetadata()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $dataLoader = $this->getDataLoader([]);
        $dataLoader->storeOutput();

        $bucketMetadata = $this->metadata->listBucketMetadata('in.c-docker-demo-testConfig');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig'
            ]
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));

        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig'
            ]
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        // let's run the data loader again.
        // This time the tables should receive 'update' metadata
        $dataLoader->storeOutput();
        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.component.id'] = 'docker-demo';
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.configuration.id'] = 'testConfig';
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));
    }


    public function testDefaultSystemConfigRowMetadata()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $dataLoader = $this->getDataLoader([], 'testRow');
        $dataLoader->storeOutput();

        $bucketMetadata = $this->metadata->listBucketMetadata('in.c-docker-demo-testConfig');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.configurationRow.id' => 'testRow'
            ]
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));

        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.configurationRow.id' => 'testRow'
            ]
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        // let's run the data loader again.
        // This time the tables should receive 'update' metadata
        $dataLoader->storeOutput();

        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.component.id'] = 'docker-demo';
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.configuration.id'] = 'testConfig';
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.configurationRow.id'] = 'testRow';
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));
    }

    public function testExecutorConfigMetadata()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );

        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-test.test',
                    ],
                ],
            ],
            'output' => [
                'tables' => [
                    [
                        'source' => 'sliced.csv',
                        'destination' => 'in.c-docker-test.out',
                        'metadata' => [
                            [
                                'key' => 'table.key.one',
                                'value' => 'table value one',
                            ],
                            [
                                'key' => 'table.key.two',
                                'value' => 'table value two',
                            ],
                        ],
                        'column_metadata' => [
                            'id' => [
                                [
                                    'key' => 'column.key.one',
                                    'value' => 'column value one id',
                                ],
                                [
                                    'key' => 'column.key.two',
                                    'value' => 'column value two id',
                                ],
                            ],
                            'text' => [
                                [
                                    'key' => 'column.key.one',
                                    'value' => 'column value one text',
                                ],
                                [
                                    'key' => 'column.key.two',
                                    'value' => 'column value two text',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $dataLoader = $this->getDataLoader($config);
        $dataLoader->storeOutput();
        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value one',
                'table.key.two' => 'table value two',
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $this->metadata->listColumnMetadata('in.c-docker-demo-testConfig.sliced.id');
        $expectedColumnMetadata = [
            'docker-demo' => [
                'column.key.one' => 'column value one id',
                'column.key.two' => 'column value two id',
            ],
        ];
        self::assertEquals($expectedColumnMetadata, $this->getMetadataValues($idColMetadata));
    }

    public function testExecutorManifestMetadata()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $manifest = '
            {
                "destination": "in.c-docker-demo-testConfig.sliced",
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
        $fs->dumpFile($this->workingDir->getDataDir() . '/out/tables/sliced.csv.manifest', $manifest);
        $dataLoader = $this->getDataLoader([]);
        $dataLoader->storeOutput();

        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value one',
                'table.key.two' => 'table value two',
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $this->metadata->listColumnMetadata('in.c-docker-demo-testConfig.sliced.id');
        $expectedColumnMetadata = [
            'docker-demo' => [
                'column.key.one' => 'column value one id',
                'column.key.two' => 'column value two id',
            ],
        ];
        self::assertEquals($expectedColumnMetadata, $this->getMetadataValues($idColMetadata));
    }

    public function testExecutorManifestMetadataCombined()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv.manifest',
            '{"metadata":[{"key":"table.key.one","value":"table value one"},'.
            '{"key":"table.key.two","value":"table value two"}],"column_metadata":{"id":['.
            '{"key":"column.key.one","value":"column value one id"},'.
            '{"key":"column.key.two","value":"column value two id"}],'.
            '"text":[{"key":"column.key.one","value":"column value one text"},'.
            '{"key":"column.key.two","value":"column value two text"}]}}'
        );

        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-test.test',
                    ],
                ],
            ],
            'output' => [
                'tables' => [
                    [
                        'source' => 'sliced.csv',
                        'destination' => 'in.c-docker-demo-testConfig.sliced',
                        'metadata' => [
                            [
                                'key' => 'table.key.one',
                                'value' => 'table value three',
                            ],
                            [
                                'key' => 'table.key.two',
                                'value' => 'table value four',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $dataLoader = $this->getDataLoader($config);
        $dataLoader->storeOutput();
        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value three',
                'table.key.two' => 'table value four',
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $this->metadata->listColumnMetadata('in.c-docker-demo-testConfig.sliced.id');
        $expectedColumnMetadata = [];
        self::assertEquals($expectedColumnMetadata, $this->getMetadataValues($idColMetadata));
    }
}
