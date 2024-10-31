<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Throwable;

class ContainerConfigurationTest extends TestCase
{
    public function testConfiguration(): void
    {
        (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => 'in.c-main.data',
                            ],
                        ],
                        'files' => [
                            [
                                'tags' => ['tag1', 'tag2'],
                                'query' => 'esquery',
                            ],
                        ],
                    ],
                    'output' => [
                        'default_bucket' => 'in.c-my-bucket',
                        'tables' => [
                            [
                                'source' => 'test.csv',
                                'destination' => 'out.c-main.data',
                            ],
                        ],
                        'files' => [
                            [
                                'source' => 'file',
                                'tags' => ['tag'],
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    ['var1' => 'val1'],
                    ['arr1' => ['var2' => 'val2']],
                ],
                'authorization' => [
                    'oauth_api' => [
                        'id' => 1234,
                        'credentials' => [
                            'token' => '123456',
                            'params' => [
                                'key' => 'val',
                            ],
                        ],
                    ],
                    'context' => 'wlm',
                ],
                'processors' => [
                    'before' => [
                        [
                            'definition' => [
                                'component' => 'a',
                                'tag' => 'latest',
                            ],
                            'parameters' => [
                                'key' => 'val',
                            ],
                        ],
                    ],
                    'after' => [
                        [
                            'definition' => [
                                'component' => 'a',
                                'tag' => '1.2.0',
                            ],
                            'parameters' => [
                                'key' => 'val',
                            ],
                        ],
                    ],
                ],
                'variables_id' => '12',
                'variables_values_id' => '21',
                'shared_code_id' => '34',
                'shared_code_row_ids' => ['345', '435'],
            ],
        ]);
        self::assertTrue(true);
    }

    public function testConfigurationWithWorkspaceConnection(): void
    {
        (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => 'in.c-main.data',
                            ],
                        ],
                        'files' => [
                            [
                                'tags' => ['tag1', 'tag2'],
                                'query' => 'esquery',
                            ],
                        ],
                    ],
                    'output' => [
                        'tables' => [
                            [
                                'source' => 'test.csv',
                                'destination' => 'out.c-main.data',
                            ],
                        ],
                        'files' => [
                            [
                                'source' => 'file',
                                'tags' => ['tag'],
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    ['var1' => 'val1'],
                    ['arr1' => ['var2' => 'val2']],
                ],
                'authorization' => [
                    'workspace' => [
                        'container' => 'my-container',
                        'connectionString' => 'aVeryLongString',
                        'account' => 'test',
                        'region' => 'mordor',
                        'credentials' => [
                            'client_id' => 'client123',
                            'private_key' => 'very-secret-private-key',
                        ],
                    ],
                    'context' => 'wlm',
                ],
                'processors' => [
                    'before' => [
                        [
                            'definition' => [
                                'component' => 'a',
                            ],
                            'parameters' => [
                                'key' => 'val',
                            ],
                        ],
                    ],
                    'after' => [
                        [
                            'definition' => [
                                'component' => 'a',
                            ],
                            'parameters' => [
                                'key' => 'val',
                            ],
                        ],
                    ],
                ],
                'variables_id' => '12',
                'variables_values_id' => '21',
                'shared_code_id' => '34',
                'shared_code_row_ids' => ['345', '435'],
            ],
        ]);
        self::assertTrue(true);
    }

    public function testRuntimeConfiguration(): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'safe' => true,
                    'image_tag' => '12.7.0',
                    'backend' => [
                        'type' => 'foo',
                        'context' => 'wml',
                        'workspace_credentials' => [
                            'id' => '1234',
                            'type' => 'snowflake',
                            '#password' => 'test',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame(
            [
                'type' => 'foo',
                'context' => 'wml',
                'workspace_credentials' => [
                    'id' => '1234',
                    'type' => 'snowflake',
                    '#password' => 'test',
                ],
            ],
            $config['runtime']['backend'],
        );
        self::assertArrayHasKey('process_timeout', $config['runtime']);
        self::assertNull($config['runtime']['process_timeout']);
    }

    public function testRuntimeBackendConfigurationHasDefaultEmptyValue(): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [],
            ],
        ]);

        self::assertSame([], $config['runtime']['backend']);

        self::assertArrayHasKey('process_timeout', $config['runtime']);
        self::assertNull($config['runtime']['process_timeout']);
    }

    public function testRuntimeBackendConfigurationIgnoreExtraKeys(): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'backend' => [
                        'type' => 'foo',
                        'context' => 'wml',
                        'extraKey' => 'ignored',
                    ],
                ],
            ],
        ]);

        self::assertSame(
            [
                'type' => 'foo',
                'context' => 'wml',
            ],
            $config['runtime']['backend'],
        );
    }

    public function testRuntimeConfigurationInvalidWorkspaceCredentials(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
        $this->expectExceptionMessage('The value "unsupported" is not allowed for path "container.runtime.backend.workspace_credentials.type". Permissible values: "snowflake"');
        (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'safe' => true,
                    'image_tag' => '12.7.0',
                    'backend' => [
                        'type' => 'foo',
                        'context' => 'wml',
                        'workspace_credentials' => [
                            'id' => '1234',
                            'type' => 'unsupported',
                            '#password' => 'test',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public static function provideValidProcessTimeout(): iterable
    {
        yield 'value' => [
            'timeout' => 300,
        ];

        yield 'null' => [
            'timeout' => null,
        ];
    }

    /** @dataProvider provideValidProcessTimeout */
    public function testRuntimeProcessTimeoutSet(?int $timeout): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'process_timeout' => $timeout,
                ],
            ],
        ]);

        self::assertArrayHasKey('process_timeout', $config['runtime']);
        self::assertSame($timeout, $config['runtime']['process_timeout']);
    }

    public static function provideInvalidProcessTimeout(): iterable
    {
        yield 'zero' => [
            'timeout' => 0,
            'expectedError' =>
                'Invalid configuration for path "container.runtime.process_timeout": must be greater than 0',
        ];

        yield 'negative' => [
            'timeout' => -10,
            'expectedError' =>
                'Invalid configuration for path "container.runtime.process_timeout": must be greater than 0',
        ];

        yield 'float' => [
            'timeout' => 10.0,
            'expectedError' =>
                'Invalid configuration for path "container.runtime.process_timeout": must be "null" or "int"',
        ];
    }

    /** @dataProvider provideInvalidProcessTimeout */
    public function testRuntimeProcessTimeoutInvalid(mixed $timeout, string $expectedError): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedError);

        (new Configuration\Container())->parse([
            'config' => [
                'runtime' => [
                    'process_timeout' => $timeout,
                ],
            ],
        ]);
    }

    public function testConfigurationWithTableFiles(): void
    {
        (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'input' => [
                        'tables' => [],
                        'files' => [],
                    ],
                    'output' => [
                        'tables' => [],
                        'files' => [],
                        'table_files' => [
                            'tags' => ['tag'],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertTrue(true);
    }

    public function testArtifactsConfigurationDoesNotAcceptsExtraKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "backend" under "container.artifacts".');

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'backend' => [
                        'type' => 'foo',
                    ],
                ],
            ],
        ]);
    }

    public function artifactsRunsConfigurationData(): iterable
    {
        yield 'empty configuration' => [
            [],
            [
                'enabled' => false,
            ],
        ];
        yield 'enabled filter - limit' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 3,
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 3,
                ],
            ],
        ];
        yield 'enabled filter - date_since' => [
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => '-7 days',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => '-7 days',
                ],
            ],
        ];
        yield 'enabled filter - limit + date_since' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 1,
                    'date_since' => '-7 days',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 1,
                    'date_since' => '-7 days',
                ],
            ],
        ];
    }

    /**
     * @dataProvider artifactsRunsConfigurationData
     */
    public function testArtifactsRunsConfiguration(
        array $runsConfiguration,
        array $expectedRunsConfiguration,
    ): void {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'runs' => $runsConfiguration,
                ],
            ],
        ]);

        self::assertSame($expectedRunsConfiguration, $config['artifacts']['runs']);
    }

    public function artifactsRunsConfigurationThrowsErrorOnInvalidConfigData(): iterable
    {
        yield 'enabled - empty configuration' => [
            [
                'enabled' => true,
            ],
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'Invalid configuration for path "container.artifacts.runs": At least one of "date_since" or "limit" parameters must be defined.',
        ];
        yield 'enabled - invalid enabled value' => [
            [
                'enabled' => 'a',
            ],
            'Invalid type for path "container.artifacts.runs.enabled". Expected "bool", but got "string".',
        ];
        yield 'enabled - invalid limit value' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 'a',
                ],
            ],
            'Invalid type for path "container.artifacts.runs.filter.limit". Expected "int", but got "string".',
        ];
        yield 'enabled - invalid date_since value' => [
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => [],
                ],
            ],
            'Invalid type for path "container.artifacts.runs.filter.date_since". Expected "scalar", but got "array".',
        ];
        yield 'extrakeys' => [
            [
                'foo' => 'bar',
            ],
            'Unrecognized option "foo" under "container.artifacts.runs". Available options are "enabled", "filter".',
        ];
    }

    /**
     * @dataProvider artifactsRunsConfigurationThrowsErrorOnInvalidConfigData
     */
    public function testArtifactsRunsConfigurationThrowsErrorOnInvalidConfig(
        array $runsConfiguration,
        string $expecterErrorMessage,
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expecterErrorMessage);

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'runs' => $runsConfiguration,
                ],
            ],
        ]);
    }

    public function artifactsSharedConfigurationData(): iterable
    {
        yield 'empty configuration' => [
            [],
            [
                'enabled' => false,
            ],
        ];
        yield 'enabled filter' => [
            [
                'enabled' => true,
            ],
            [
                'enabled' => true,
            ],
        ];
    }

    /**
     * @dataProvider artifactsSharedConfigurationData
     */
    public function testArtifactsSharedConfiguration(
        array $sharedConfiguration,
        array $expectedSharedConfiguration,
    ): void {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'shared' => $sharedConfiguration,
                ],
            ],
        ]);

        self::assertSame($expectedSharedConfiguration, $config['artifacts']['shared']);
    }

    public function artifactsSharedConfigurationThrowsErrorOnInvalidConfigData(): iterable
    {
        yield 'enabled - invalid enabled value' => [
            [
                'enabled' => 'a',
            ],
            'Invalid type for path "container.artifacts.shared.enabled". Expected "bool", but got "string".',
        ];
        yield 'extrakeys' => [
            [
                'foo' => 'bar',
            ],
            'Unrecognized option "foo" under "container.artifacts.shared". Available option is "enabled".',
        ];
    }

    /**
     * @dataProvider artifactsSharedConfigurationThrowsErrorOnInvalidConfigData
     */
    public function testArtifactsSharedConfigurationThrowsErrorOnInvalidConfig(
        array $sharedConfiguration,
        string $expecterErrorMessage,
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expecterErrorMessage);

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'shared' => $sharedConfiguration,
                ],
            ],
        ]);
    }

    public function artifactsCustomConfigurationData(): iterable
    {
        yield 'empty configuration' => [
            [],
            [
                'enabled' => false,
            ],
        ];
        yield 'enabled filter - component' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                ],
            ],
        ];
        yield 'enabled filter - config_id' => [
            [
                'enabled' => true,
                'filter' => [
                    'config_id' => '123456',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'config_id' => '123456',
                ],
            ],
        ];
        yield 'enabled filter - branch_id' => [
            [
                'enabled' => true,
                'filter' => [
                    'branch_id' => 'main',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'branch_id' => 'main',
                ],
            ],
        ];
        yield 'enabled filter' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                ],
            ],
        ];
        yield 'enabled filter - limit' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'limit' => 123,
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'limit' => 123,
                ],
            ],
        ];
        yield 'enabled filter - date_since' => [
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'date_since' => '-7 days',
                ],
            ],
            [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                    'config_id' => '123456',
                    'branch_id' => 'main',
                    'date_since' => '-7 days',
                ],
            ],
        ];
    }

    /**
     * @dataProvider artifactsCustomConfigurationData
     */
    public function testArtifactsCustomConfiguration(
        array $customConfiguration,
        array $expectedCustomConfiguration,
    ): void {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'custom' => $customConfiguration,
                ],
            ],
        ]);

        self::assertSame($expectedCustomConfiguration, $config['artifacts']['custom']);
    }

    public function artifactsCustomConfigurationThrowsErrorOnInvalidConfigData(): iterable
    {
        yield 'enabled - empty configuration' => [
            [
                'enabled' => true,
            ],
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'Invalid configuration for path "container.artifacts.custom": "component_id", "config_id" and "branch_id" parameters must be defined.',
        ];
        yield 'enabled - invalid enabled value' => [
            [
                'enabled' => 'a',
            ],
            'Invalid type for path "container.artifacts.custom.enabled". Expected "bool", but got "string".',
        ];
        yield 'enabled - invalid limit value' => [
            [
                'enabled' => true,
                'filter' => [
                    'limit' => 'a',
                ],
            ],
            'Invalid type for path "container.artifacts.custom.filter.limit". Expected "int", but got "string".',
        ];
        yield 'enabled - invalid date_since value' => [
            [
                'enabled' => true,
                'filter' => [
                    'date_since' => [],
                ],
            ],
            'Invalid type for path "container.artifacts.custom.filter.date_since". Expected "scalar", but got "array".',
        ];
        yield 'extrakeys' => [
            [
                'foo' => 'bar',
            ],
            'Unrecognized option "foo" under "container.artifacts.custom". Available options are "enabled", "filter".',
        ];
    }

    /**
     * @dataProvider artifactsCustomConfigurationThrowsErrorOnInvalidConfigData
     */
    public function testArtifactsCustomConfigurationThrowsErrorOnInvalidConfig(
        array $customConfiguration,
        string $expecterErrorMessage,
    ): void {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expecterErrorMessage);

        (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'custom' => $customConfiguration,
                ],
            ],
        ]);
    }

    public function testArtifactsHavingMultipleFiltersEnabled(): void
    {
        $config = (new Configuration\Container())->parse([
            'config' => [
                'artifacts' => [
                    'runs' => [
                        'enabled' => true,
                        'filter' => [
                            'limit' => 1,
                        ],
                    ],
                    'custom' => [
                        'enabled' => true,
                        'filter' => [
                            'component_id' => 'keboola.orchestrator',
                        ],
                    ],
                    'shared' => [
                        'enabled' => true,
                    ],
                ],
            ],
        ]);

        self::assertSame([
            'runs' => [
                'enabled' => true,
                'filter' => [
                    'limit' => 1,
                ],
            ],
            'custom' => [
                'enabled' => true,
                'filter' => [
                    'component_id' => 'keboola.orchestrator',
                ],
            ],
            'shared' => ['enabled' => true],
        ], $config['artifacts']);
    }

    public function testConfigurationWithReadonlyRole(): void
    {
        (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'input' => [
                        'read_only_storage_access' => true,
                        'tables' => [],
                        'files' => [],
                    ],
                    'output' => [
                        'tables' => [],
                        'files' => [],
                        'table_files' => [
                            'tags' => ['tag'],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertTrue(true);
    }

    public function testConfigurationWithDataTypes(): void
    {
        // default value
        $config = (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'tables' => [],
                    ],
                ],
            ],
        ]);
        self::assertArrayNotHasKey('data_type_support', $config['storage']['output']);

        // custom value
        $config = (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'data_type_support' => 'authoritative',
                        'tables' => [],
                    ],
                ],
            ],
        ]);
        self::assertEquals('authoritative', $config['storage']['output']['data_type_support']);
    }

    public function testConfigurationWithInvalidDataTypesValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'The value "invalid" is not allowed for path "container.storage.output.data_type_support". ' .
            'Permissible values: "authoritative", "hints", "none"',
        );
        (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'data_type_support' => 'invalid',
                        'tables' => [],
                    ],
                ],
            ],
        ]);
    }

    public function testConfigurationWithTableModification(): void
    {
        // default value
        $config = (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'tables' => [],
                    ],
                ],
            ],
        ]);
        self::assertArrayNotHasKey('table_modifications', $config['storage']['output']);

        // custom value
        $config = (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'table_modifications' => 'all',
                        'tables' => [],
                    ],
                ],
            ],
        ]);
        self::assertEquals('all', $config['storage']['output']['table_modifications']);
    }

    public function testConfigurationWithInvalidTableModificationValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'The value "invalid" is not allowed for path "container.storage.output.table_modifications". ' .
            'Permissible values: "none", "non-destructive", "all"',
        );
        (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'table_modifications' => 'invalid',
                        'tables' => [],
                    ],
                ],
            ],
        ]);
    }

    public function testConfigurationWithTreatValuesAsNull(): void
    {
        // default value
        $config = (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'tables' => [],
                    ],
                ],
            ],
        ]);
        self::assertArrayNotHasKey('treat_values_as_null', $config['storage']['output']);

        // custom value
        $config = (new Configuration\Container())->parse([
            'config' => [
                'storage' => [
                    'output' => [
                        'treat_values_as_null' => [
                            'first',
                            'second',
                        ],
                        'tables' => [],
                    ],
                ],
            ],
        ]);
        self::assertEquals([
            'first',
            'second',
        ], $config['storage']['output']['treat_values_as_null']);
    }
}
