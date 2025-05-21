<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\CommonExceptions\UserExceptionInterface;
use Keboola\Csv\CsvFile;
use Keboola\Datatype\Definition\BaseType;
use Keboola\Datatype\Definition\GenericStorage;
use Keboola\Datatype\Definition\Snowflake;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\OutputMapping\Writer\Table\MappingDestination;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
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
        $dataLoader = $this->getOutputDataLoader([]);
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);

        $tableQueue->waitForAll();
        self::assertTrue(
            $this->clientWrapper->getBasicClient()->tableExists('in.c-docker-demo-testConfig.sliced'),
        );
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
        $dataLoader = $this->getOutputDataLoader(['output' => ['default_bucket' => 'in.c-test-override']]);
        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);

        $tableQueue->waitForAll();
        self::assertFalse($this->clientWrapper->getBasicClient()->tableExists('in.c-test-demo-testConfig.sliced'));
        self::assertTrue($this->clientWrapper->getBasicClient()->tableExists('in.c-test-override.sliced'));
    }

    public function testNoConfigDefaultBucketException(): void
    {
        $dataLoader = $this->getOutputDataLoader([], configId: null);

        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage('Configuration ID not set');
        $dataLoader->storeOutput();
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
        $component = new ComponentSpecification([
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
        $storageConfig = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig.test',
                        'destination' => 'test.csv',
                    ],
                ],
            ],
        ];
        $dataLoader = $this->getInputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
        );

        $this->expectException(UserExceptionInterface::class);
        $this->expectExceptionMessage(
            'The buckets "in.c-docker-demo-testConfig" come from a development ' .
            'branch and must not be used directly in input mapping.',
        );

        $dataLoader->loadInputData();
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
        $component = new ComponentSpecification([
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
        $storageConfig = [
            'input' => [
                'tables' => [
                    [
                        'source' => 'in.c-docker-demo-testConfig.test',
                        'destination' => 'test.csv',
                    ],
                ],
            ],
        ];

        $dataLoader = $this->getInputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
        );

        $storageState = $dataLoader->loadInputData();
        self::assertCount(1, $storageState->inputTableResult->getTables());
        self::assertCount(0, $storageState->inputFileStateList->jsonSerialize());
    }

    public function testTypedTableCreate(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/typed-data.csv',
            '1,text,123.45,3.3333,true,2020-02-02,2020-02-02 02:02:02',
        );
        $component = new ComponentSpecification([
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
        $storageConfig = [
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
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NATIVE_TYPES'),
            ),
        );

        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
            clientWrapper: $clientWrapper,
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
        $component = new ComponentSpecification([
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
        $storageConfig = [
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
                                        'type' => BaseType::INTEGER,
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
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
            clientWrapper: $clientWrapper,
        );

        $tableQueue = $dataLoader->storeOutput();

        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        $tableDetails = $clientWrapper->getBasicClient()->getTable(
            'in.c-docker-demo-testConfig.authoritative-types-test',
        );
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
        $component = new ComponentSpecification([
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
                                        'type' => BaseType::INTEGER,
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
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        try {
            $clientWrapper->getBasicClient()->dropTable($tableId);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $config,
            component: $component,
            clientWrapper: $clientWrapper,
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
            ['key' => 'KBC.datatype.basetype', 'value' => 'INTEGER', 'provider' => 'docker-demo'],
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
        $component = new ComponentSpecification([
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
        $storageConfig = [
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
                                        'type' => BaseType::INTEGER,
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
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
            clientWrapper: $clientWrapper,
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

        $component = new ComponentSpecification([
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
        $storageConfig = [
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
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );
        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
            clientWrapper: $clientWrapper,
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

        $component = new ComponentSpecification([
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
        $storageConfig = [
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
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_FEATURE_NEW_NATIVE_TYPES'),
            ),
        );

        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
            clientWrapper: $clientWrapper,
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


    public function testTreatValuesAsNull(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/data.csv',
            '1,text,NAN',
        );
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/data.csv.manifest',
            (string) json_encode([
                'columns' => ['id', 'name', 'price'],
            ]),
        );
        $component = new ComponentSpecification([
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
        $storageConfig = [
            'output' => [
                'treat_values_as_null' => ['NAN'],
                'tables' => [
                    [
                        'source' => 'data.csv',
                        'destination' => 'in.c-docker-demo-testConfig.treated-values-test',
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN'),
            ),
        );

        $this->clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig', 'in');
        $clientWrapper->getTableAndFileStorageClient()->createTableDefinition(
            'in.c-docker-demo-testConfig',
            [
                'name' => 'treated-values-test',
                'columns' => [
                    [
                        'name' => 'id',
                        'basetype' => BaseType::INTEGER,
                    ],
                    [
                        'name' => 'name',
                        'basetype' => BaseType::STRING,
                    ],
                    [
                        'name' => 'price',
                        'basetype' => BaseType::NUMERIC,
                    ],
                ],
            ],
        );

        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
            clientWrapper: $clientWrapper,
        );

        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        /** @var array|string $data */
        $data = $clientWrapper->getTableAndFileStorageClient()->getTableDataPreview(
            'in.c-docker-demo-testConfig.treated-values-test',
            [
                'format' => 'json',
            ],
        );

        self::assertIsArray($data);
        self::assertArrayHasKey('rows', $data);
        self::assertSame(
            [
                [
                    [
                        'columnName' => 'id',
                        'value' => '1',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => 'name',
                        'value' => 'text',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => 'price',
                        'value' => null,
                        'isTruncated' => false,
                    ],
                ],
            ],
            $data['rows'],
        );
    }

    public function testTreatValuesAsNullDisable(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/data.csv',
            '1,"",123',
        );
        $fs->dumpFile(
            $this->workingDir->getDataDir() . '/out/tables/data.csv.manifest',
            (string) json_encode([
                'columns' => ['id', 'name', 'price'],
            ]),
        );
        $component = new ComponentSpecification([
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
        $storageConfig = [
            'output' => [
                'treat_values_as_null' => [],
                'tables' => [
                    [
                        'source' => 'data.csv',
                        'destination' => 'in.c-docker-demo-testConfig.treated-values-test',
                    ],
                ],
            ],
        ];
        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN'),
            ),
        );

        $this->clientWrapper->getBasicClient()->createBucket('docker-demo-testConfig', 'in');
        $clientWrapper->getTableAndFileStorageClient()->createTableDefinition(
            'in.c-docker-demo-testConfig',
            [
                'name' => 'treated-values-test',
                'columns' => [
                    [
                        'name' => 'id',
                        'basetype' => BaseType::INTEGER,
                    ],
                    [
                        'name' => 'name',
                        'basetype' => BaseType::STRING,
                    ],
                    [
                        'name' => 'price',
                        'basetype' => BaseType::INTEGER,
                    ],
                ],
            ],
        );

        $dataLoader = $this->getOutputDataLoader(
            storageConfig: $storageConfig,
            component: $component,
            clientWrapper: $clientWrapper,
        );

        $tableQueue = $dataLoader->storeOutput();
        self::assertNotNull($tableQueue);
        $tableQueue->waitForAll();

        /** @var array|string $data */
        $data = $clientWrapper->getTableAndFileStorageClient()->getTableDataPreview(
            'in.c-docker-demo-testConfig.treated-values-test',
            [
                'format' => 'json',
            ],
        );

        self::assertIsArray($data);
        self::assertArrayHasKey('rows', $data);
        self::assertSame(
            [
                [
                    [
                        'columnName' => 'id',
                        'value' => '1',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => 'name',
                        'value' => '',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => 'price',
                        'value' => '123',
                        'isTruncated' => false,
                    ],
                ],
            ],
            $data['rows'],
        );
    }
}
