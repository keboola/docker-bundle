<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Generator;
use Keboola\Csv\CsvFile;
use Keboola\Datatype\Definition\BaseType;
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
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\InputMapping\Table\Result;
use Keboola\OutputMapping\Writer\Table\MappingDestination;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\StorageApiToken;
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

    public function testExecutorDefaultBucket(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
        );
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv.manifest',
            (string) json_encode(['destination' => 'sliced']),
        );
        $dataLoader = $this->getDataLoader([]);
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);

        $tableQueue->waitForAll();
        self::assertTrue(
            $this->clientWrapper->getBasicClient()->tableExists('in.c-docker-demo-testConfig.sliced'),
        );
        self::assertEquals([], $dataLoader->getWorkspaceCredentials());
        self::assertNull($dataLoader->getWorkspaceBackendSize());
    }

    public function testExecutorDefaultBucketOverride(): void
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
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
        );
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/sliced.csv.manifest',
            (string) json_encode(['destination' => 'sliced']),
        );
        $dataLoader = $this->getDataLoader(['output' => ['default_bucket' => 'in.c-test-override']]);
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);

        $tableQueue->waitForAll();
        self::assertFalse($this->clientWrapper->getBasicClient()->tableExists('in.c-test-demo-testConfig.sliced'));
        self::assertTrue($this->clientWrapper->getBasicClient()->tableExists('in.c-test-override.sliced'));
        self::assertEquals([], $dataLoader->getWorkspaceCredentials());
        self::assertNull($dataLoader->getWorkspaceBackendSize());
    }

    public function testNoConfigDefaultBucketException(): void
    {
        self::expectException(UserException::class);
        self::expectExceptionMessage('Configuration ID not set');
        new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition([], $this->getDefaultBucketComponent()),
            new OutputFilter(10000),
        );
    }

    public function testExecutorInvalidOutputMapping(): void
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
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
        );
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Invalid type for path "container.storage.output.tables.0.primary_key". Expected "array", but got "string"',
        );
        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition(['storage' => $config], $this->getNoDefaultBucketComponent()),
            new OutputFilter(10000),
        );
        $dataLoader->storeOutput();
    }

    /** @dataProvider invalidStagingProvider */
    public function testWorkspaceInvalid(string $input, string $output, string $error): void
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
            new OutputFilter(10000),
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
            'bigquery-snowflake' => [
                'workspace-bigquery',
                'workspace-snowflake',
                'Component staging setting mismatch - input: "workspace-bigquery", output: "workspace-snowflake".',
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
            new OutputFilter(10000),
        );
        $dataLoader->storeOutput();
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(
            ['host', 'warehouse', 'database', 'schema', 'user', 'password', 'account'],
            array_keys($credentials),
        );
        self::assertNotEmpty($credentials['user']);
        self::assertNotNull($dataLoader->getWorkspaceBackendSize());
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
            new OutputFilter(10000),
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

    public function testWorkspaceRedshiftNoPreserve(): void
    {
        try {
            $this->clientWrapper->getBasicClient()->dropBucket(
                'in.c-testWorkspaceRedshiftNoPreserve',
                ['force' => true, 'async' => true],
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
            'redshift',
        );
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->temp->getTmpFolder() . '/data.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
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
            true,
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
            ],
        );

        $dataLoader = new DataLoader(
            $this->clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component, $configId),
            new OutputFilter(10000),
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['host', 'warehouse', 'database', 'schema', 'user', 'password'], array_keys($credentials));
        self::assertNotEmpty($credentials['user']);

        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('docker-demo')
                ->setConfigurationId($configId),
        );
        self::assertCount(1, $workspaces);

        // the workspace should be the same
        self::assertSame($workspace['connection']['user'], $credentials['user']);
        self::assertSame($workspace['connection']['schema'], $credentials['schema']);

        // but the original table does not exist (workspace was cleared)
        try {
            $this->clientWrapper->getBasicClient()->writeTableAsyncDirect(
                'in.c-testWorkspaceRedshiftNoPreserve.test',
                ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'original'],
            );
            self::fail('Must throw exception');
        } catch (ClientException $e) {
            self::assertStringContainsString('Table "original" not found in schema', $e->getMessage());
        }

        // the loaded table exists
        $this->clientWrapper->getBasicClient()->writeTableAsyncDirect(
            'in.c-testWorkspaceRedshiftNoPreserve.test',
            ['dataWorkspaceId' => $workspaces[0]['id'], 'dataTableName' => 'test'],
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
            ],
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
            new OutputFilter(10000),
        );
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'The buckets "in.c-docker-demo-testConfig" come from a development ' .
            'branch and must not be used directly in input mapping.',
        );
        $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testBranchMappingEnabled(): void
    {
        $this->clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig', 'in');
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->temp->getTmpFolder() . '/data.csv',
            "id,text,row_number\n1,test,1\n1,test,2\n1,test,3",
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
            ],
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
            new OutputFilter(10000),
        );
        $storageState = $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
        self::assertInstanceOf(Result::class, $storageState->getInputTableResult());
        self::assertInstanceOf(InputFileStateList::class, $storageState->getInputFileStateList());
    }

    public function testTypedTableCreate(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,text,123.45,3.3333,true,2020-02-02,2020-02-02 02:02:02',
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
                                    ['length' => '17', 'nullable' => false],
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
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NATIVE_TYPES'),
            ),
        );
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000),
        );
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable('in.c-docker-demo-testConfig.fixed-type-test');
        self::assertTrue($tableDetails['isTyped']);

        $tableDefinitionColumns = $tableDetails['definition']['columns'];
        self::assertDataType($tableDefinitionColumns, 'int', Snowflake::TYPE_NUMBER);
        self::assertDataType($tableDefinitionColumns, 'string', Snowflake::TYPE_VARCHAR);
        self::assertDataType($tableDefinitionColumns, 'decimal', Snowflake::TYPE_NUMBER);
        self::assertDataType($tableDefinitionColumns, 'float', Snowflake::TYPE_FLOAT);
        self::assertDataType($tableDefinitionColumns, 'bool', Snowflake::TYPE_BOOLEAN);
        self::assertDataType($tableDefinitionColumns, 'date', Snowflake::TYPE_DATE);
        self::assertDataType($tableDefinitionColumns, 'timestamp', Snowflake::TYPE_TIMESTAMP_LTZ);
    }

    public function testTypedTableCreateWithAuthoritativeSchemaConfig(): void
    {
        $tableId = 'in.c-docker-demo-testConfig.authoritative-types-test';
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,text,123.45,3.3333,true,2020-02-02,2020-02-02 02:02:02',
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
            'dataTypesConfiguration' => [
                'dataTypesSupport' => 'authoritative',
            ],
        ]);
        $config = [
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'typed-data.csv',
                            'destination' => $tableId,
                            'schema' => [
                                [
                                    'name' => 'int',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::NUMERIC,
                                        ],
                                    ],
                                    'primary_key' => true,
                                    'nullable' => false,
                                ],
                                [
                                    'name' => 'string',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                            'length' => '17',
                                        ],
                                    ],
                                    'nullable' => false,
                                ],
                                [
                                    'name' => 'decimal',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::NUMERIC,
                                            'length' => '10,2',
                                        ],
                                    ],
                                ],
                                [
                                    'name' => 'float',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::FLOAT,
                                        ],
                                    ],
                                ],
                                [
                                    'name' => 'bool',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::BOOLEAN,
                                        ],
                                    ],
                                ],
                                [
                                    'name' => 'date',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::DATE,
                                        ],
                                    ],
                                ],
                                [
                                    'name' => 'timestamp',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::TIMESTAMP,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000),
        );
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable('in.c-docker-demo-testConfig.fixed-type-test');
        self::assertTrue($tableDetails['isTyped']);

        $tableDefinitionColumns = $tableDetails['definition']['columns'];

        self::assertEquals(['int'], $tableDetails['definition']['primaryKeysNames']);
        self::assertDataType($tableDefinitionColumns, 'int', Snowflake::TYPE_NUMBER);
        self::assertDataType($tableDefinitionColumns, 'string', Snowflake::TYPE_VARCHAR);
        self::assertDataType($tableDefinitionColumns, 'decimal', Snowflake::TYPE_NUMBER);
        self::assertDataType($tableDefinitionColumns, 'float', Snowflake::TYPE_FLOAT);
        self::assertDataType($tableDefinitionColumns, 'bool', Snowflake::TYPE_BOOLEAN);
        self::assertDataType($tableDefinitionColumns, 'date', Snowflake::TYPE_DATE);
        self::assertDataType($tableDefinitionColumns, 'timestamp', Snowflake::TYPE_TIMESTAMP_LTZ);
    }

    public function testTypedTableCreateWithHintsSchemaConfig(): void
    {
        $tableId = 'in.c-hints-types.hints-types-test';
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,text,123.45',
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
            'dataTypesConfiguration' => [
                'dataTypesSupport' => 'hints',
            ],
        ]);
        $config = [
            'storage' => [
                'output' => [
                    'tables' => [
                        [
                            'source' => 'typed-data.csv',
                            'destination' => $tableId,
                            'schema' => [
                                [
                                    'name' => 'int',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::NUMERIC,
                                        ],
                                    ],
                                    'primary_key' => true,
                                ],
                                [
                                    'name' => 'string',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                            'length' => '17',
                                        ],
                                    ],
                                ],
                                [
                                    'name' => 'decimal',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::NUMERIC,
                                            'length' => '10,2',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $clientWrapper->getBasicClient()->dropTable($tableId);

        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000),
        );
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable($tableId);
        self::assertTrue($tableDetails['isTyped']);

        $tableDefinitionColumns = $tableDetails['definition']['columns'];

        self::assertDataType($tableDefinitionColumns, 'int', Snowflake::TYPE_VARCHAR);
        self::assertDataType($tableDefinitionColumns, 'string', Snowflake::TYPE_VARCHAR);
        self::assertDataType($tableDefinitionColumns, 'decimal', Snowflake::TYPE_VARCHAR);

        $columnMetadata = $tableDetails['columnMetadata'];
        self::assertArrayHasKey('int', $columnMetadata);

        $intColumnMetadata = array_values(array_filter(
            $tableDetails['columnMetadata']['int'],
            fn(array $metadata) =>
                in_array(
                    $metadata['key'],
                    ['KBC.datatype.basetype', 'KBC.datatype.length', 'KBC.datatype.nullable'],
                    true,
                ) && $metadata['provider'] === 'docker-demo',
        ));

        self::assertCount(2, $intColumnMetadata);
        self::assertEquals([
            ['key' => 'KBC.datatype.basetype', 'value' => 'NUMERIC', 'provider' => 'docker-demo'],
            ['key' => 'KBC.datatype.nullable', 'value' => '1', 'provider' => 'docker-demo'],
        ], array_map(function ($v) {
            unset($v['id'], $v['timestamp']);
            return $v;
        }, $intColumnMetadata));

        $stringColumnMetadata = array_values(array_filter(
            $tableDetails['columnMetadata']['string'],
            fn(array $metadata) =>
                in_array(
                    $metadata['key'],
                    ['KBC.datatype.basetype', 'KBC.datatype.length', 'KBC.datatype.nullable'],
                    true,
                ) && $metadata['provider'] === 'docker-demo',
        ));

        self::assertCount(3, $stringColumnMetadata);
        self::assertEquals([
            ['key' => 'KBC.datatype.basetype', 'value' => 'STRING', 'provider' => 'docker-demo'],
            ['key' => 'KBC.datatype.length', 'value' => '17', 'provider' => 'docker-demo'],
            ['key' => 'KBC.datatype.nullable', 'value' => '1', 'provider' => 'docker-demo'],
        ], array_map(function ($v) {
            unset($v['id'], $v['timestamp']);
            return $v;
        }, $stringColumnMetadata));

        $decimalColumnMetadata = array_values(array_filter(
            $tableDetails['columnMetadata']['decimal'],
            fn(array $metadata) =>
                in_array(
                    $metadata['key'],
                    ['KBC.datatype.basetype', 'KBC.datatype.length', 'KBC.datatype.nullable'],
                    true,
                ) && $metadata['provider'] === 'docker-demo',
        ));

        self::assertCount(3, $decimalColumnMetadata);
        self::assertEquals([
            ['key' => 'KBC.datatype.basetype', 'value' => 'NUMERIC', 'provider' => 'docker-demo'],
            ['key' => 'KBC.datatype.length', 'value' => '10,2', 'provider' => 'docker-demo'],
            ['key' => 'KBC.datatype.nullable', 'value' => '1', 'provider' => 'docker-demo'],
        ], array_map(function ($v) {
            unset($v['id'], $v['timestamp']);
            return $v;
        }, $decimalColumnMetadata));
    }

    public function testTypedTableCreateWithSchemaConfigMetadata(): void
    {
        $tableId = 'in.c-docker-demo-testConfigMetadata.fixed-type-test';
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,text',
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
                            'destination' => $tableId,
                            'description' => 'table description',
                            'table_metadata' => [
                                'key1' => 'value1',
                                'key2' => 'value2',
                            ],
                            'schema' => [
                                [
                                    'name' => 'int',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::NUMERIC,
                                        ],
                                    ],
                                    'primary_key' => true,
                                    'nullable' => false,
                                    'metadata' => [
                                        'key1' => 'value1',
                                        'key2' => 'value2',

                                    ],
                                ],
                                [
                                    'name' => 'string',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                            'length' => '17',
                                        ],
                                    ],
                                    'description' => 'column description',
                                    'nullable' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000),
        );
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable($tableId);
        self::assertTrue($tableDetails['isTyped']);

        $tableMetadata = array_values(array_filter(
            $tableDetails['metadata'],
            fn(array $metadata) => in_array($metadata['key'], ['key1', 'key2', 'KBC.description'], true),
        ));
        self::assertCount(3, $tableMetadata);
        self::assertEquals([
            ['key' => 'key1', 'value' => 'value1', 'provider' => 'docker-demo'],
            ['key' => 'key2', 'value' => 'value2', 'provider' => 'docker-demo'],
            ['key' => 'KBC.description', 'value' => 'table description', 'provider' => 'docker-demo'],
        ], array_map(function ($v) {
            unset($v['id'], $v['timestamp']);
            return $v;
        }, $tableMetadata));

        $intColumnMetadata = array_values(array_filter(
            $tableDetails['columnMetadata']['int'],
            fn(array $metadata) => in_array($metadata['key'], ['key1', 'key2'], true),
        ));
        self::assertCount(2, $intColumnMetadata);
        self::assertEquals([
            ['key' => 'key1', 'value' => 'value1', 'provider' => 'docker-demo'],
            ['key' => 'key2', 'value' => 'value2', 'provider' => 'docker-demo'],
        ], array_map(function ($v) {
            unset($v['id'], $v['timestamp']);
            return $v;
        }, $intColumnMetadata));

        $stringColumnMetadata = array_values(array_filter(
            $tableDetails['columnMetadata']['string'],
            fn(array $metadata) => in_array($metadata['key'], ['KBC.description'], true),
        ));
        self::assertCount(1, $stringColumnMetadata);
        self::assertEquals([
            ['key' => 'KBC.description', 'value' => 'column description', 'provider' => 'docker-demo'],
        ], array_map(function ($v) {
            unset($v['id'], $v['timestamp']);
            return $v;
        }, $stringColumnMetadata));
    }

    public function testTypedTableModifyTableStructure(): void
    {
        $tableId = 'in.c-testTypedTableModifyTableStructure.typed-test';
        $tableInfo = new MappingDestination($tableId);

        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );

        if ($clientWrapper->getBasicClient()->bucketExists($tableInfo->getBucketId())) {
            $clientWrapper->getBasicClient()->dropBucket(
                $tableInfo->getBucketId(),
                [
                    'force' => true,
                ],
            );
        }

        // prepare storage in project
        $clientWrapper->getBasicClient()->createBucket(
            $tableInfo->getBucketName(),
            $tableInfo->getBucketStage(),
        );
        $clientWrapper->getBasicClient()->createTableDefinition(
            $tableInfo->getBucketId(),
            [
                'name' => $tableInfo->getTableName(),
                'primaryKeysNames' => ['Id'],
                'columns' => [
                    [
                        'name' => 'Id',
                        'definition' => [
                            'type' => BaseType::STRING,
                            'nullable' => false,
                        ],
                    ],
                    [
                        'name' => 'Name',
                        'definition' => [
                            'type' => BaseType::STRING,
                            'length' => '255',
                            'nullable' => false,
                        ],
                    ],
                    [
                        'name' => 'foo',
                        'definition' => [
                            'type' => BaseType::STRING,
                            'length' => '255',
                            'nullable' => false,
                        ],
                    ],
                ],
            ],
        );

        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,text,text2,text3',
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
                            'destination' => $tableId,
                            'description' => 'table description',
                            'table_metadata' => [
                                'key1' => 'value1',
                                'key2' => 'value2',
                            ],
                            'schema' => [
                                [
                                    'name' => 'Id',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                        ],
                                    ],
                                    'primary_key' => false,
                                    'nullable' => false,
                                ],
                                [
                                    'name' => 'Name',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                            'length' => '255',
                                        ],
                                    ],
                                    'primary_key' => true,
                                    'nullable' => false,
                                ],
                                [
                                    'name' => 'foo',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                            'length' => '500',
                                        ],
                                    ],
                                    'primary_key' => true,
                                    'nullable' => false,
                                ],
                                [
                                    'name' => 'New Column',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                            'length' => '255',
                                        ],
                                    ],
                                    'nullable' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000),
        );
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable($tableId);
        self::assertTrue($tableDetails['isTyped']);

        // PKs is changed
        self::assertEquals(['Name', 'foo'], $tableDetails['definition']['primaryKeysNames']);

        // length is changed
        self::assertEquals('500', $tableDetails['definition']['columns'][0]['definition']['length']);

        // nullable is changed
        self::assertFalse($tableDetails['definition']['columns'][1]['definition']['nullable']);

        // new column is added and Webalized
        self::assertEquals('New_Column', $tableDetails['definition']['columns'][3]['name']);
    }

    public function testTypedTableLoadWithDatabaseColumnAliases(): void
    {
        $tableId = 'in.c-testTypedTableLoadWithDatabaseColumnAliases.typed-test';
        $tableInfo = new MappingDestination($tableId);

        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );

        if ($clientWrapper->getBasicClient()->bucketExists($tableInfo->getBucketId())) {
            $clientWrapper->getBasicClient()->dropBucket(
                $tableInfo->getBucketId(),
                [
                    'force' => true,
                ],
            );
        }

        // prepare storage in project
        $clientWrapper->getBasicClient()->createBucket(
            $tableInfo->getBucketName(),
            $tableInfo->getBucketStage(),
        );
        $clientWrapper->getBasicClient()->createTableDefinition(
            $tableInfo->getBucketId(),
            [
                'name' => $tableInfo->getTableName(),
                'primaryKeysNames' => [],
                'columns' => [
                    [
                        'name' => 'varchar',
                        'definition' => [
                            'type' => Snowflake::TYPE_VARCHAR,
                        ],
                    ],
                    [
                        'name' => 'number',
                        'definition' => [
                            'type' => Snowflake::TYPE_NUMBER,
                        ],
                    ],
                    [
                        'name' => 'float',
                        'definition' => [
                            'type' => Snowflake::TYPE_FLOAT,
                        ],
                    ],
                ],
            ],
        );

        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,1,1.0',
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
                            'destination' => $tableId,
                            'description' => 'table description',
                            'table_metadata' => [
                                'key1' => 'value1',
                                'key2' => 'value2',
                            ],
                            'schema' => [
                                [
                                    'name' => 'varchar',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::STRING,
                                        ],
                                        'snowflake' => [
                                            'type' => Snowflake::TYPE_NVARCHAR2,
                                        ],
                                    ],
                                ],
                                [
                                    'name' => 'number',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::INTEGER,
                                        ],
                                        'snowflake' => [
                                            'type' => Snowflake::TYPE_INTEGER,
                                        ],
                                    ],
                                ],
                                [
                                    'name' => 'float',
                                    'data_type' => [
                                        'base' => [
                                            'type' => BaseType::FLOAT,
                                        ],
                                        'snowflake' => [
                                            'type' => Snowflake::TYPE_DOUBLE,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $dataLoader = new DataLoader(
            $clientWrapper,
            new NullLogger(),
            $this->workingDir->getDataDir(),
            new JobDefinition($config, $component),
            new OutputFilter(10000),
        );
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable($tableId);
        self::assertTrue($tableDetails['isTyped']);
    }

    private static function assertDataType(array $columns, string $columnName, string $expectedType): void
    {
        $columnDefinition = current(array_filter($columns, fn(array $column) => $column['name'] === $columnName));
        self::assertSame($expectedType, $columnDefinition['definition']['type']);
    }

    public function testWorkspaceCleanupSuccess(): void
    {
        $componentId = 'keboola.runner-workspace-test';
        $component = new Component([
            'id' => $componentId,
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
        $clientMock = $this->createMock(BranchAwareClient::class);
        $clientMock->method('verifyToken')->willReturn($this->clientWrapper->getBasicClient()->verifyToken());
        $configuration = new Configuration();
        $configuration->setName('testWorkspaceCleanup');
        $configuration->setComponentId($componentId);
        $configuration->setConfiguration([]);
        $componentsApi = new Components($this->clientWrapper->getBasicClient());
        $configId = $componentsApi->addConfiguration($configuration)['id'];

        $clientMock->expects(self::never())
            ->method('apiPostJson');
        $clientMock->expects(self::never())
            ->method('apiDelete');

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($clientMock);
        $clientWrapperMock->method('getBranchClient')->willReturn($clientMock);

        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapperMock,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component, $configId),
            new OutputFilter(10000),
        );
        // immediately calling cleanWorkspace without using it means it was not initialized
        $dataLoader->cleanWorkspace();

        $listOptions = new ListConfigurationWorkspacesOptions();
        $listOptions->setComponentId($componentId)->setConfigurationId($configId);
        $workspaces = $componentsApi->listConfigurationWorkspaces($listOptions);
        self::assertCount(0, $workspaces);
        $componentsApi->deleteConfiguration($componentId, $configId);
    }

    public function testWorkspaceCleanupWhenInitialized(): void
    {
        $componentId = 'keboola.runner-workspace-test';
        $component = new Component([
            'id' => $componentId,
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
        $clientMock = $this->createMock(BranchAwareClient::class);
        $clientMock->method('verifyToken')->willReturn($this->clientWrapper->getBasicClient()->verifyToken());
        $configuration = new Configuration();
        $configuration->setName('testWorkspaceCleanup');
        $configuration->setComponentId($componentId);
        $configuration->setConfiguration([]);
        $componentsApi = new Components($this->clientWrapper->getBasicClient());
        $configId = $componentsApi->addConfiguration($configuration)['id'];

        $clientMock->method('apiPostJson')
            ->willReturnCallback(function (...$args) {
                return $this->clientWrapper->getBasicClient()->apiPostJson(...$args);
            });
        $clientMock->expects(self::once())
            ->method('apiDelete')
            ->willReturnCallback(function (...$args) {
                return $this->clientWrapper->getBasicClient()->apiDelete(...$args);
            });

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($clientMock);
        $clientWrapperMock->method('getBranchClient')->willReturn($clientMock);

        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapperMock,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component, $configId),
            new OutputFilter(10000),
        );

        // this causes the workspaces to initialize
        $workspace = $dataLoader->getWorkspaceCredentials();

        self::assertArrayHasKey('host', $workspace);
        self::assertArrayHasKey('warehouse', $workspace);
        self::assertArrayHasKey('database', $workspace);
        self::assertArrayHasKey('schema', $workspace);
        self::assertArrayHasKey('user', $workspace);
        self::assertArrayHasKey('password', $workspace);
        self::assertArrayHasKey('account', $workspace);

        $dataLoader->cleanWorkspace();
        $listOptions = new ListConfigurationWorkspacesOptions();
        $listOptions->setComponentId($componentId)->setConfigurationId($configId);
        $workspaces = $componentsApi->listConfigurationWorkspaces($listOptions);
        self::assertCount(0, $workspaces);
        $componentsApi->deleteConfiguration($componentId, $configId);
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
        $clientMock = $this->createMock(BranchAwareClient::class);
        $clientMock->method('verifyToken')->willReturn($this->clientWrapper->getBasicClient()->verifyToken());
        $clientMock->method('apiPostJson')
            ->willReturnCallback(function (...$args) {
                return $this->clientWrapper->getBasicClient()->apiPostJson(...$args);
            });
        $clientMock->expects(self::once())
            ->method('apiDelete')
            ->willThrowException(
                new ClientException('boo'),
            );

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($clientMock);
        $clientWrapperMock->method('getBranchClient')->willReturn($clientMock);

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
            new OutputFilter(10000),
        );
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertArrayHasKey('host', $credentials);
        self::assertArrayHasKey('password', $credentials);

        $dataLoader->cleanWorkspace();
        self::assertTrue($logger->hasErrorThatContains('Failed to cleanup workspace: boo'));
        $componentsApi->deleteConfiguration('keboola.runner-workspace-test', $configId);
    }

    /**
     * @dataProvider dataTypeSupportProvider
     */
    public function testDataTypeSupport(
        bool $hasFeature,
        ?string $componentType,
        ?string $configType,
        string $expectedType,
    ): void {
        $componentId = 'keboola.runner-workspace-test';
        $componentConfig = [
            'id' => $componentId,
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
        ];
        if ($componentType) {
            $componentConfig['dataTypesConfiguration']['dataTypesSupport'] = $componentType;
        }

        $component = new Component($componentConfig);

        $clientMock = $this->createMock(BranchAwareClient::class);
        $clientMock->method('verifyToken')->willReturn($this->clientWrapper->getBasicClient()->verifyToken());

        $getTokenMock = $this->createMock(StorageApiToken::class);
        $getTokenMock->method('hasFeature')->with('new-native-types')->willReturn($hasFeature);

        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($clientMock);
        $clientWrapperMock->method('getBranchClient')->willReturn($clientMock);
        $clientWrapperMock->method('getToken')->willReturn($getTokenMock);

        $configuration = new Configuration();
        $configuration->setName('testWorkspaceCleanup');
        $configuration->setComponentId($componentId);
        $configuration->setConfiguration([]);

        $componentsApi = new Components($this->clientWrapper->getBasicClient());
        $configId = $componentsApi->addConfiguration($configuration)['id'];

        $logger = new TestLogger();

        $jobConfig = [];
        if ($configType) {
            $jobConfig = [
                'storage' => [
                    'output' => [
                        'data_type_support' => $configType,
                    ],
                ],
            ];
        }
        $dataLoader = new DataLoader(
            $clientWrapperMock,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition($jobConfig, $component, $configId),
            new OutputFilter(10000),
        );

        self::assertEquals($expectedType, $dataLoader->getDataTypeSupport());
    }

    public function dataTypeSupportProvider(): Generator
    {

        yield 'default-values' => [
            true,
            null,
            null,
            'none',
        ];

        yield 'component-override' => [
            true,
            'hints',
            null,
            'hints',
        ];

        yield 'config-override' => [
            true,
            null,
            'authoritative',
            'authoritative',
        ];

        yield 'component-config-override' => [
            true,
            'hints',
            'authoritative',
            'authoritative',
        ];

        yield 'component-override-without-feature' => [
            false,
            'hints',
            null,
            'none',
        ];

        yield 'config-override-without-feature' => [
            false,
            null,
            'authoritative',
            'none',
        ];

        yield 'component-config-override-without-feature' => [
            false,
            'hints',
            'authoritative',
            'none',
        ];
    }
}
