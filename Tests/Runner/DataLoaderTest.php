<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderTest extends BaseDataLoaderTest
{
    public function setUp()
    {
        parent::setUp();
        $this->cleanup();
    }

    public function testExecutorDefaultBucket()
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
        self::assertNotNull($tableQueue);

        $tableQueue->waitForAll();
        self::assertTrue($this->client->tableExists('in.c-docker-demo-testConfig.sliced'));
        self::assertEquals([], $dataLoader->getWorkspaceCredentials());
    }

    public function testNoConfigDefaultBucketException()
    {
        self::expectException(UserException::class);
        self::expectExceptionMessage('Configuration ID not set');
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $this->getDefaultBucketComponent()),
            new OutputFilter()
        );
    }

    public function testExecutorInvalidOutputMapping()
    {
        $config = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig.test',
                    ],
                ],
            ],
            'output' => [
                'tables' => [
                    [
                        'source' => 'sliced.csv',
                        'destination' => 'in.c-docker-demo-testConfig.out',
                        // erroneous lines
                        'primary_key' => 'col1',
                        'incremental' => 1,
                    ],
                ],
            ],
        ];
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        self::expectException(UserException::class);
        self::expectExceptionMessage('Invalid type for path "container.storage.output.tables.0.primary_key". Expected array, but got string');
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition(['storage' => $config], $this->getNoDefaultBucketComponent()),
            new OutputFilter()
        );
        $dataLoader->storeOutput();
    }

    /**
     * @dataProvider invalidStagingProvider
     * @param string $input
     * @param string $output
     * @param string $error
     */
    public function testWorkspaceInvalid($input, $output, $error)
    {
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => $input,
                    'output' => $output,
                ],
            ],
        ]);
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage($error);
        new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component),
            new OutputFilter()
        );
    }

    public function invalidStagingProvider()
    {
        return [
            'snowflake-redshift' => [
                'workspace-snowflake',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-snowflake", output: "workspace-redshift".'
            ],
            'redshift-snowflake' => [
                'workspace-redshift',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-redshift", output: "workspace-snowflake".'
            ],
            'snowflake-synapse' => [
                'workspace-snowflake',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-snowflake", output: "workspace-synapse".'
            ],
            'redshift-synapse' => [
                'workspace-redshift',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-redshift", output: "workspace-synapse".'
            ],
            'synapse-snowflake' => [
                'workspace-synapse',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-synapse", output: "workspace-snowflake".'
            ],
            'synapse-redshift' => [
                'workspace-synapse',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-synapse", output: "workspace-redshift".'
            ],
            'abs-snowflake' => [
                'workspace-abs',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-abs", output: "workspace-snowflake".'
            ],
            'abs-redshift' => [
                'workspace-abs',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-abs", output: "workspace-redshift".'
            ],
            'abs-synapse' => [
                'workspace-abs',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-abs", output: "workspace-synapse".'
            ],
        ];
    }

    public function testWorkspace()
    {
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ]);
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component),
            new OutputFilter()
        );
        $dataLoader->storeOutput();
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['host', 'warehouse', 'database', 'schema', 'user', 'password'], array_keys($credentials));
        self::assertNotEmpty($credentials['user']);
    }

    public function testWorkspaceRedshiftNoPreserve()
    {
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        try {
            $clientWrapper->getBasicClient()->dropBucket(
                'in.c-testWorkspaceRedshiftNoPreserve',
                ['force' => true]
            );
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $clientWrapper->getBasicClient()->createBucket(
            'testWorkspaceRedshiftNoPreserve',
            'in',
            'description',
            'redshift'
        );
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->temp->getTmpFolder() . '/data.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $csv = new CsvFile($this->temp->getTmpFolder() . '/data.csv');
        $clientWrapper->getBasicClient()->createTable('in.c-testWorkspaceRedshiftNoPreserve', 'test', $csv);

        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'workspace-redshift',
                    'output' => 'workspace-redshift',
                ],
            ],
        ]);
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-testWorkspaceRedshiftNoPreserve.test',
                            'destination' => 'test',
                        ],
                    ],
                ],
            ],
        ];
        $configuration = new Configuration();
        $configuration->setName('testWorkspaceRedshiftNoPreserve');
        $configuration->setComponentId('docker-demo');
        $configuration->setConfiguration($config);
        $componentsApi = new Components($clientWrapper->getBasicClient());
        $configId = $componentsApi->addConfiguration($configuration)['id'];

        // create redshift workspace and load a table into it
        $workspace = $componentsApi->createConfigurationWorkspace(
            'docker-demo',
            $configId,
            ['backend' => 'redshift']
        );
        $workspaceApi = new Workspaces($clientWrapper->getBasicClient());
        $workspaceApi->loadWorkspaceData(
            $workspace['id'],
            [
                'input' => [
                    [
                        'source' => 'in.c-testWorkspaceRedshiftNoPreserve.test',
                        'destination' => 'original',
                    ],
                ],
            ]
        );
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component, $configId),
            new OutputFilter()
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['host', 'warehouse', 'database', 'schema', 'user', 'password'], array_keys($credentials));
        self::assertNotEmpty($credentials['user']);

        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('docker-demo')
                ->setConfigurationId($configId)
        );
        self::assertCount(1, $workspaces);

        // the workspace should be the same
        self::assertSame($workspace['connection']['user'], $credentials['user']);
        self::assertSame($workspace['connection']['schema'], $credentials['schema']);

        // but the original table does not exist (workspace was cleared)
        try {
            $clientWrapper->getBasicClient()->writeTableAsyncDirect(
                'in.c-testWorkspaceRedshiftNoPreserve.test',
                ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'original']
            );
            self::fail('Must throw exception');
        } catch (ClientException $e) {
            self::assertContains('Table "original" not found in schema', $e->getMessage());
        }

        // the loaded table exists
        $clientWrapper->getBasicClient()->writeTableAsyncDirect(
            'in.c-testWorkspaceRedshiftNoPreserve.test',
            ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'test']
        );
    }

    public function testBranchMappingDisabled()
    {
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        $clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig', 'in');
        $metadata = new Metadata($clientWrapper->getBasicClient());
        $metadata->postBucketMetadata(
            'in.c-docker-demo-testConfig',
            'system',
            [
                [
                    'key' => 'KBC.createdBy.branch.id',
                    'value' => '1234',
                ],
            ]
        );
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
        ]);
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-demo-testConfig.test',
                            'destination' => 'test.csv',
                        ],
                    ],
                ],
            ],
        ];
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter()
        );
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'The buckets "in.c-docker-demo-testConfig" come from a development ' .
            'branch and must not be used directly in input mapping.'
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testBranchMappingEnabled()
    {
        $clientWrapper = new ClientWrapper($this->client, null, null, ClientWrapper::BRANCH_MAIN);
        $clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig', 'in');
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->temp->getTmpFolder() . '/data.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $csv = new CsvFile($this->temp->getTmpFolder() . '/data.csv');
        $clientWrapper->getBasicClient()->createTable('in.c-docker-demo-testConfig', 'test', $csv);
        $metadata = new Metadata($clientWrapper->getBasicClient());
        $metadata->postBucketMetadata(
            'in.c-docker-demo-testConfig',
            'system',
            [
                [
                    'key' => 'KBC.createdBy.branch.id',
                    'value' => '1234',
                ],
            ]
        );
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
            'features' => ['dev-mapping-allowed'],
        ]);
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-demo-testConfig.test',
                            'destination' => 'test.csv',
                        ],
                    ],
                ],
            ],
        ];
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter()
        );
        $storageState = $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
        self::assertInstanceOf(InputTableStateList::class, $storageState->getInputTableStateList());
        self::assertInstanceOf(InputFileStateList::class, $storageState->getInputFileStateList());
    }
}
