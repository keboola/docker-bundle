<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Generator;
use Keboola\CommonExceptions\UserExceptionInterface;
use Keboola\Csv\CsvFile;
use Keboola\Datatype\Definition\Common;
use Keboola\Datatype\Definition\GenericStorage;
use Keboola\Datatype\Definition\Snowflake;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\Table\Result;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Filesystem\Filesystem;

class DataLoaderTest extends BaseDataLoaderTest
{
    public function setUp(): void
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
        self::assertTrue(
            $this->clientWrapper->getBasicClient()->tableExists('in.c-docker-demo-testConfig.sliced')
        );
        self::assertEquals([], $dataLoader->getWorkspaceCredentials());
    }

    public function testExecutorDefaultBucketOverride()
    {
        try {
            $this->clientWrapper->getBasicClient()->dropBucket(
                'in.c-test-override',
                ['force' => true, 'async' => true],
            );
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
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
        $dataLoader = $this->getDataLoader(['output' => ['default_bucket' => 'in.c-test-override']]);
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);

        $tableQueue->waitForAll();
        self::assertFalse($this->clientWrapper->getBasicClient()->tableExists('in.c-test-demo-testConfig.sliced'));
        self::assertTrue($this->clientWrapper->getBasicClient()->tableExists('in.c-test-override.sliced'));
        self::assertEquals([], $dataLoader->getWorkspaceCredentials());
    }

    public function testNoConfigDefaultBucketException()
    {
        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $this->getDefaultBucketComponent()),
            new OutputFilter(10000)
        );

        self::expectException(UserExceptionInterface::class);
        self::expectExceptionMessage('Configuration ID not set');
        $dataLoader->storeOutput();
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
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Invalid type for path "container.storage.output.tables.0.primary_key". Expected "array", but got "string"'
        );
        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition(['storage' => $config], $this->getNoDefaultBucketComponent()),
            new OutputFilter(10000)
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
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => $input,
                    'output' => $output,
                ],
            ],
        ]);
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage($error);
        new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component),
            new OutputFilter(10000)
        );
    }

    public function invalidStagingProvider(): array
    {
        return [
            'snowflake-redshift' => [
                'workspace-snowflake',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-snowflake", output: "workspace-redshift".',
            ],
            'redshift-snowflake' => [
                'workspace-redshift',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-redshift", output: "workspace-snowflake".',
            ],
            'snowflake-synapse' => [
                'workspace-snowflake',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-snowflake", output: "workspace-synapse".',
            ],
            'redshift-synapse' => [
                'workspace-redshift',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-redshift", output: "workspace-synapse".',
            ],
            'synapse-snowflake' => [
                'workspace-synapse',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-synapse", output: "workspace-snowflake".',
            ],
            'synapse-redshift' => [
                'workspace-synapse',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-synapse", output: "workspace-redshift".',
            ],
            'abs-snowflake' => [
                'workspace-abs',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-abs", output: "workspace-snowflake".',
            ],
            'abs-redshift' => [
                'workspace-abs',
                'workspace-redshift',
                'Component staging setting mismatch - input: "workspace-abs", output: "workspace-redshift".',
            ],
            'abs-synapse' => [
                'workspace-abs',
                'workspace-synapse',
                'Component staging setting mismatch - input: "workspace-abs", output: "workspace-synapse".',
            ],
        ];
    }

    public function testWorkspace(): void
    {
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ]);
        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component),
            new OutputFilter(10000)
        );
        $dataLoader->storeOutput();
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(
            ['host', 'warehouse', 'database', 'schema', 'user', 'password', 'account'],
            array_keys($credentials)
        );
        self::assertNotEmpty($credentials['user']);
    }

    /**
     * @dataProvider readonlyFlagProvider
     */
    public function testWorkspaceReadOnly(bool $readOnlyWorkspace): void
    {
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ]);
        $config = [
            'storage' => [
                'input' => [
                    'read_only_storage_access' => $readOnlyWorkspace,
                    'tables' => [],
                    'files' => [],
                ],
            ],
        ];
        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000)
        );
        $dataLoader->storeOutput();
        $credentials = $dataLoader->getWorkspaceCredentials();

        $schemaName = $credentials['schema'];
        $workspacesApi = new Workspaces($this->clientWrapper->getBasicClient());
        $workspaces = $workspacesApi->listWorkspaces();
        $readonlyWorkspace = null;
        foreach ($workspaces as $workspace) {
            if ($workspace['connection']['schema'] === $schemaName) {
                $readonlyWorkspace = $workspace;
            }
        }
        self::assertNotNull($readonlyWorkspace);
        self::assertSame($readOnlyWorkspace, $readonlyWorkspace['readOnlyStorageAccess']);
        $dataLoader->cleanWorkspace();
    }

    public function readonlyFlagProvider(): Generator
    {
        yield 'readonly on' => [true];
        yield 'readonly off' => [false];
    }

    public function testWorkspaceRedshiftNoPreserve()
    {
        try {
            $this->clientWrapper->getBasicClient()->dropBucket(
                'in.c-testWorkspaceRedshiftNoPreserve',
                ['force' => true, 'async' => true]
            );
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $this->clientWrapper->getBasicClient()->createBucket(
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
        $this->clientWrapper->getBasicClient()->createTable('in.c-testWorkspaceRedshiftNoPreserve', 'test', $csv);

        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
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
        $componentsApi = new Components($this->clientWrapper->getBasicClient());
        $configId = $componentsApi->addConfiguration($configuration)['id'];

        // create redshift workspace and load a table into it
        $workspace = $componentsApi->createConfigurationWorkspace(
            'docker-demo',
            $configId,
            ['backend' => 'redshift'],
            true
        );
        $workspaceApi = new Workspaces($this->clientWrapper->getBasicClient());
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

        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component, $configId),
            new OutputFilter(10000)
        );
        $dataLoader->loadInputData();
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
            $this->clientWrapper->getBasicClient()->writeTableAsyncDirect(
                'in.c-testWorkspaceRedshiftNoPreserve.test',
                ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'original']
            );
            self::fail('Must throw exception');
        } catch (ClientException $e) {
            self::assertStringContainsString('Table "original" not found in schema', $e->getMessage());
        }

        // the loaded table exists
        $this->clientWrapper->getBasicClient()->writeTableAsyncDirect(
            'in.c-testWorkspaceRedshiftNoPreserve.test',
            ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'test']
        );
    }

    public function testBranchMappingDisabled(): void
    {
        $this->clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig', 'in');
        $metadata = new Metadata($this->clientWrapper->getBasicClient());
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
                    'tag' => 'master',
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
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000)
        );
        self::expectException(UserExceptionInterface::class);
        self::expectExceptionMessage(
            'The buckets "in.c-docker-demo-testConfig" come from a development ' .
            'branch and must not be used directly in input mapping.'
        );
        $dataLoader->loadInputData();
    }

    public function testBranchMappingEnabled()
    {
        $this->clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig', 'in');
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->temp->getTmpFolder() . '/data.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3"
        );
        $csv = new CsvFile($this->temp->getTmpFolder() . '/data.csv');
        $this->clientWrapper->getBasicClient()->createTable('in.c-docker-demo-testConfig', 'test', $csv);
        $metadata = new Metadata($this->clientWrapper->getBasicClient());
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
                    'tag' => 'master',
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
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000)
        );
        $storageState = $dataLoader->loadInputData();
        self::assertInstanceOf(Result::class, $storageState->getInputTableResult());
        self::assertInstanceOf(InputFileStateList::class, $storageState->getInputFileStateList());
    }

    public function testTypedTableCreate()
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,text,123.45,3.3333,true,2020-02-02,2020-02-02 02:02:02'
        );
        $component = new Component([
            'id' => 'docker-demo',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => 'local',
                    'output' => 'local',
                ],
            ],
        ]);
        $config = [
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'typed-data.csv',
                            'destination' => 'in.c-docker-demo-testConfig.fixed-type-test',
                            'columns' => ['int', 'string', 'decimal', 'float', 'bool', 'date', 'timestamp'],
                            'primary_key' => ['int'],
                            'column_metadata' => [
                                'int' => (new GenericStorage('int', ['nullable' => false]))->toMetadata(),
                                'string' => (new GenericStorage(
                                    'varchar',
                                    ['length' => '17', 'nullable' => false]
                                ))->toMetadata(),
                                'decimal' => (new GenericStorage('decimal', ['length' => '10.2']))->toMetadata(),
                                'float' => (new GenericStorage('float'))->toMetadata(),
                                'bool' => (new GenericStorage('bool'))->toMetadata(),
                                'date' => (new GenericStorage('date'))->toMetadata(),
                                'timestamp' => (new GenericStorage('timestamp'))->toMetadata(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NATIVE_TYPES')
            )
        );
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000)
        );
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable('in.c-docker-demo-testConfig.fixed-type-test');
        self::assertTrue($tableDetails['isTyped']);

        self::assertDataType($tableDetails['columnMetadata']['int'], Snowflake::TYPE_NUMBER);
        self::assertDataType($tableDetails['columnMetadata']['string'], Snowflake::TYPE_VARCHAR);
        self::assertDataType($tableDetails['columnMetadata']['decimal'], Snowflake::TYPE_NUMBER);
        self::assertDataType($tableDetails['columnMetadata']['float'], Snowflake::TYPE_FLOAT);
        self::assertDataType($tableDetails['columnMetadata']['bool'], Snowflake::TYPE_BOOLEAN);
        self::assertDataType($tableDetails['columnMetadata']['date'], Snowflake::TYPE_DATE);
        self::assertDataType($tableDetails['columnMetadata']['timestamp'], Snowflake::TYPE_TIMESTAMP_LTZ);
    }

    private static function assertDataType($metadata, $expectedType): void
    {
        foreach ($metadata as $metadatum) {
            if ($metadatum['key'] === Common::KBC_METADATA_KEY_TYPE) {
                self::assertSame($expectedType, $metadatum['value']);
                return;
            }
        }
        self::fail('Metadata key ' . Common::KBC_METADATA_KEY_TYPE . ' not found');
    }

    public function testWorkspaceCleanupFailure(): void
    {
        $component = new Component([
            'id' => 'keboola.runner-workspace-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.runner-workspace-test',
                    'tag' => '1.6.2',
                ],
                'staging-storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ]);
        $clientMock = $this->createMock(Client::class);
        $clientMock->method('verifyToken')->willReturn($this->clientWrapper->getBasicClient()->verifyToken());

        // exception is not thrown outside
        $clientMock->expects(self::once())->method('apiPostJson')->willThrowException(
            new ClientException('boo')
        );

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($clientMock);
        $clientWrapperMock->method('getBranchClientIfAvailable')->willReturn($clientMock);

        $configuration = new Configuration();
        $configuration->setName('testWorkspaceCleanup');
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setConfiguration([]);
        $componentsApi = new Components($this->clientWrapper->getBasicClient());
        $configId = $componentsApi->addConfiguration($configuration)['id'];

        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapperMock,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component, $configId),
            new OutputFilter(10000)
        );
        $dataLoader->cleanWorkspace();
        self::assertTrue($logger->hasErrorThatContains('Failed to cleanup workspace: boo'));
        $componentsApi->deleteConfiguration('keboola.runner-workspace-test', $configId);
    }
}
