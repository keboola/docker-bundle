<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderMetadataTest extends BaseDataLoaderTest
{
    public function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    /**
     * Transform metadata into a key-value array
     */
    private function getMetadataValues(array $metadata): array
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
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv.manifest',
            json_encode(['destination' => 'sliced'])
        );
        $dataLoader = $this->getDataLoader([]);
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();

        $bucketMetadata = $this->metadata->listBucketMetadata('in.c-docker-demo-testConfig');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
            ],
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));

        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system'
            => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.lastUpdatedBy.component.id' => 'docker-demo',
                'KBC.lastUpdatedBy.configuration.id' => 'testConfig',
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        // let's run the data loader again.
        // This time the tables should receive 'update' metadata
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();
        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.component.id'] = 'docker-demo';
        $expectedTableMetadata['system']['KBC.lastUpdatedBy.configuration.id'] = 'testConfig';
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));
    }

    public function createBranch(Client $client, $branchName)
    {
        $branches = new DevBranches($client);
        foreach ($branches->listBranches() as $branch) {
            if ($branch['name'] === $branchName) {
                $branches->deleteBranch($branch['id']);
            }
        }
        return $branches->createBranch($branchName)['id'];
    }

    public function testDefaultSystemMetadataBranch()
    {
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                STORAGE_API_URL,
                STORAGE_API_TOKEN_MASTER
            )
        );
        foreach ($clientWrapper->getBasicClient()->listBuckets() as $bucket) {
            if (preg_match('/^in\.c\-[0-9]+\-docker\-demo\-testConfig$/', $bucket['id'])) {
                $clientWrapper->getBasicClient()->dropBucket($bucket['id'], ['force' => true]);
            }
        }

        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv.manifest',
            json_encode(['destination' => 'sliced'])
        );

        $branchId = $this->createBranch($clientWrapper->getBasicClient(), 'test-branch');
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                STORAGE_API_URL,
                STORAGE_API_TOKEN_MASTER,
                $branchId
            )
        );
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $this->getDefaultBucketComponent(), 'testConfig'),
            new OutputFilter(10000)
        );
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();

        $branchBucketId = sprintf('in.c-%s-docker-demo-testConfig', $branchId);
        $bucketMetadata = $this->metadata->listBucketMetadata($branchBucketId);
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.branch.id' => (string) $branchId,
            ],
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));

        $tableMetadata = $this->metadata->listTableMetadata(sprintf('%s.sliced', $branchBucketId));
        $expectedTableMetadata = [
            'system'
            => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.branch.id' => (string) $branchId,
                'KBC.lastUpdatedBy.component.id' => 'docker-demo',
                'KBC.lastUpdatedBy.configuration.id' => 'testConfig',
                'KBC.lastUpdatedBy.branch.id' => (string) $branchId,
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));
    }

    public function testDefaultSystemConfigRowMetadata()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv.manifest',
            json_encode(['destination' => 'sliced'])
        );
        $dataLoader = $this->getDataLoader([], 'testRow');
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();

        $bucketMetadata = $this->metadata->listBucketMetadata('in.c-docker-demo-testConfig');
        $expectedBucketMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.configurationRow.id' => 'testRow',
            ],
        ];
        self::assertEquals($expectedBucketMetadata, $this->getMetadataValues($bucketMetadata));

        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.configurationRow.id' => 'testRow',
                'KBC.lastUpdatedBy.component.id' => 'docker-demo',
                'KBC.lastUpdatedBy.configuration.id' => 'testConfig',
                'KBC.lastUpdatedBy.configurationRow.id' => 'testRow',
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        // let's run the data loader again.
        // This time the tables should receive 'update' metadata
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();

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
                        'source' => 'in.c-runner-test.test',
                    ],
                ],
            ],
            'output' => [
                'tables' => [
                    [
                        'source' => 'sliced.csv',
                        'destination' => 'in.c-runner-test.out',
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
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();
        $tableMetadata = $this->metadata->listTableMetadata('in.c-runner-test.out');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.lastUpdatedBy.configuration.id' => 'testConfig',
                'KBC.lastUpdatedBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value one',
                'table.key.two' => 'table value two',
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $this->metadata->listColumnMetadata('in.c-runner-test.out.id');
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
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();

        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.lastUpdatedBy.configuration.id' => 'testConfig',
                'KBC.lastUpdatedBy.component.id' => 'docker-demo',
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
                        'source' => 'in.c-runner-test.test',
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
                        'column_metadata' => [
                            'id' => [
                                [
                                    'key' => 'column.key.two',
                                    'value' => 'a new column value two id',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $dataLoader = $this->getDataLoader($config);
        $tableQueue = $dataLoader->storeOutput();
        $tableQueue->waitForAll();
        $tableMetadata = $this->metadata->listTableMetadata('in.c-docker-demo-testConfig.sliced');
        $expectedTableMetadata = [
            'system' => [
                'KBC.createdBy.configuration.id' => 'testConfig',
                'KBC.createdBy.component.id' => 'docker-demo',
                'KBC.lastUpdatedBy.configuration.id' => 'testConfig',
                'KBC.lastUpdatedBy.component.id' => 'docker-demo',
            ],
            'docker-demo' => [
                'table.key.one' => 'table value three',
                'table.key.two' => 'table value four',
            ],
        ];
        self::assertEquals($expectedTableMetadata, $this->getMetadataValues($tableMetadata));

        $idColMetadata = $this->metadata->listColumnMetadata('in.c-docker-demo-testConfig.sliced.id');
        $expectedColumnMetadata = [
            'docker-demo' => [
                'column.key.one' => 'column value one id',
                'column.key.two' => 'a new column value two id',
            ],
        ];
        self::assertEquals($expectedColumnMetadata, $this->getMetadataValues($idColMetadata));
    }
}
