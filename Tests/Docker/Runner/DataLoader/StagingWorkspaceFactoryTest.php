<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Runner\DataLoader\StagingWorkspaceFacade;
use Keboola\DockerBundle\Docker\Runner\DataLoader\StagingWorkspaceFactory;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Configuration\Configuration;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Workspace\Configuration\NetworkPolicy;
use Keboola\StagingProvider\Workspace\Configuration\NewWorkspaceConfig;
use Keboola\StagingProvider\Workspace\Workspace;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StagingProvider\Workspace\WorkspaceWithCredentials;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApiBranch\StorageApiToken;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StagingWorkspaceFactoryTest extends TestCase
{
    private readonly LoggerInterface $logger;
    private readonly TestHandler $logsHandler;

    public function setUp(): void
    {
        $this->logsHandler = new TestHandler();
        $this->logger = new Logger('test', [$this->logsHandler]);
    }

    /** @dataProvider stagingMismatchProvider */
    public function testComponentStagingSettingMismatch(
        string $inputStaging,
        string $outputStaging,
        string $expectedError,
    ): void {
        $component = new ComponentSpecification([
            'id' => 'test-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'test/test',
                ],
                'staging-storage' => [
                    'input' => $inputStaging,
                    'output' => $outputStaging,
                ],
            ],
        ]);

        $factory = new StagingWorkspaceFactory(
            $this->createMock(WorkspaceProvider::class),
            $this->logger,
        );

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage($expectedError);

        $factory->createStagingWorkspaceFacade(
            $this->createMock(StorageApiToken::class),
            $component,
            Configuration::fromArray([]),
            null,
        );
    }

    public static function stagingMismatchProvider(): iterable
    {
        yield 'snowflake-bigquery' => [
            'inputStaging' => 'workspace-snowflake',
            'outputStaging' => 'workspace-bigquery',
            'expectedError' =>
                'Component staging setting mismatch - input: "workspace-snowflake", output: "workspace-bigquery".',
        ];

        yield 'bigquery-snowflake' => [
            'inputStaging' => 'workspace-bigquery',
            'outputStaging' => 'workspace-snowflake',
            'expectedError' =>
                'Component staging setting mismatch - input: "workspace-bigquery", output: "workspace-snowflake".',
        ];
    }

    /** @dataProvider provideNewWorkspaceTestData */
    public function testCreateNewWorkspace(
        array $tokenFlags,
        StagingType $componentStaging,
        array $configData,
        NewWorkspaceConfig $expectedWorkspaceConfig,
    ): void {
        $storageApiToken = new StorageApiToken([
            'owner' => $tokenFlags,
        ], 'token-value');

        $component = new ComponentSpecification([
            'id' => 'test-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'test/test',
                ],
                'staging-storage' => [
                    'input' => $componentStaging->value,
                    'output' => $componentStaging->value,
                ],
            ],
        ]);

        // this is just simplification for the test, in production the backendType is produced by Connection
        // Connection backend has the same name as our StagingType, just without the "workspace-" prefix
        $backendType = preg_replace('/^workspace-/', '', $componentStaging->value);

        $createdWorkspace = new WorkspaceWithCredentials(
            new Workspace(
                id: 'workspace-id',
                backendType: $backendType,
                backendSize: 'small',
                loginType: WorkspaceLoginType::DEFAULT,
            ),
            [
                'password' => '<PASSWORD>',
            ],
        );

        $workspaceProvider = $this->createMock(WorkspaceProvider::class);
        $workspaceProvider->expects(self::once())
            ->method('createNewWorkspace')
            ->with(
                $storageApiToken,
                $expectedWorkspaceConfig,
            )
            ->willReturn($createdWorkspace)
        ;

        $factory = new StagingWorkspaceFactory(
            $workspaceProvider,
            $this->logger,
        );

        $stagingWorkspaceFacade = $factory->createStagingWorkspaceFacade(
            $storageApiToken,
            $component,
            Configuration::fromArray($configData),
            null,
        );

//        self::assertTrue($this->logsHandler->hasNotice('Creating a new ephemeral workspace.'));
        self::assertEquals(
            new StagingWorkspaceFacade(
                $workspaceProvider,
                $this->logger,
                $createdWorkspace,
                isReusable: false,
            ),
            $stagingWorkspaceFacade,
        );

        // TODO prepare also functional test
    }

    public static function provideNewWorkspaceTestData(): iterable
    {
        yield 'basic Snowflake workspace' => [
            'tokenFlags' => [
                'hasSnowflake' => true,
            ],
            'componentStaging' => StagingType::WorkspaceSnowflake,
            'configData' => [],
            'expectedWorkspaceConfig' => new NewWorkspaceConfig(
                stagingType: StagingType::WorkspaceSnowflake,
                componentId: 'test-component',
                configId: null,
                size: null,
                useReadonlyRole: false,
                networkPolicy: NetworkPolicy::SYSTEM,
                loginType: null,
            ),
        ];

        yield 'basic BigQuery workspace' => [
            'tokenFlags' => [
                'hasBigquery' => true,
            ],
            'componentStaging' => StagingType::WorkspaceBigquery,
            'configData' => [],
            'expectedWorkspaceConfig' => new NewWorkspaceConfig(
                stagingType: StagingType::WorkspaceBigquery,
                componentId: 'test-component',
                configId: null,
                size: null,
                useReadonlyRole: false,
                networkPolicy: NetworkPolicy::SYSTEM,
                loginType: null,
            ),
        ];

        yield 'readonly workspace' => [
            'tokenFlags' => [
                'hasSnowflake' => true,
            ],
            'componentStaging' => StagingType::WorkspaceSnowflake,
            'configData' => [
                'storage' => [
                    'input' => [
                        'read_only_storage_access' => true,
                        'tables' => [],
                        'files' => [],
                    ],
                ],
            ],
            'expectedWorkspaceConfig' => new NewWorkspaceConfig(
                stagingType: StagingType::WorkspaceSnowflake,
                componentId: 'test-component',
                configId: null,
                size: null,
                useReadonlyRole: true,
                networkPolicy: NetworkPolicy::SYSTEM,
                loginType: null,
            ),
        ];
    }

    public function testExternallyManagedWorkspace(): void
    {
        $storageApiToken = new StorageApiToken([
            'owner' => [
                'hasSnowflake' => true,
            ],
        ], 'token-value');

        $component = new ComponentSpecification([
            'id' => 'test-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'test/test',
                ],
                'staging-storage' => [
                    'input' => 'workspace-snowflake',
                    'output' => 'workspace-snowflake',
                ],
            ],
        ]);

        $returnedWorkspace = new Workspace(
            id: 'workspace-id',
            backendType: 'snowflake',
            backendSize: 'small',
            loginType: WorkspaceLoginType::DEFAULT,
        );

        $returnedWorkspace = new WorkspaceWithCredentials(
            new Workspace(
                id: 'workspace-id',
                backendType: 'snowflake',
                backendSize: 'small',
                loginType: WorkspaceLoginType::DEFAULT,
            ),
            [
                'password' => '<PASSWORD>',
            ],
        );

        $workspaceProvider = $this->createMock(WorkspaceProvider::class);
        $workspaceProvider->expects(self::once())
            ->method('getExistingWorkspace')
            ->with(
                'workspace-id',
                [
                    'password' => '<PASSWORD>',
                ],
            )
            ->willReturn($returnedWorkspace)
        ;

        $factory = new StagingWorkspaceFactory(
            $workspaceProvider,
            $this->logger,
        );

        $stagingWorkspaceFacade = $factory->createStagingWorkspaceFacade(
            $storageApiToken,
            $component,
            Configuration::fromArray([
                'runtime' => [
                    'backend' => [
                        'workspace_credentials' => [
                            'id' => 'workspace-id',
                            'type' => 'snowflake',
                            '#password' => '<PASSWORD>',
                        ],
                    ],
                ],
            ]),
            null,
        );

        self::assertTrue($this->logsHandler->hasNotice('Using provided workspace "workspace-id".'));
        self::assertEquals(
            new StagingWorkspaceFacade(
                $workspaceProvider,
                $this->logger,
                $returnedWorkspace,
                isReusable: true, // this is important!
            ),
            $stagingWorkspaceFacade,
        );
    }
}
