<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\DataLoader\ExternallyManagedWorkspaceCredentials;
use Keboola\DockerBundle\Docker\Runner\DataLoader\WorkspaceProviderFactory;
use Keboola\InputMapping\Staging\AbstractStrategyFactory;
use Keboola\KeyGenerator\PemKeyCertificatePair;
use Keboola\StagingProvider\Provider\ExistingWorkspaceProvider;
use Keboola\StagingProvider\Provider\InvalidWorkspaceProvider;
use Keboola\StagingProvider\Provider\NewWorkspaceProvider;
use Keboola\StagingProvider\Provider\SnowflakeKeypairGenerator;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApi\Workspaces;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class WorkspaceProviderFactoryTest extends TestCase
{
    private TestHandler $testLogHandler;
    private Logger $testLogger;

    public function setUp(): void
    {
        $this->testLogHandler = new TestHandler();
        $this->testLogger = new Logger('test', [$this->testLogHandler]);
    }

    public function testInvalidWorkspaceType(): void
    {
        $componentsApiClient = $this->createMock(Components::class);
        $workspacesApiClient = $this->createMock(Workspaces::class);
        $snowflakeKeyPairGenerator = $this->createMock(SnowflakeKeypairGenerator::class);

        $factory = new WorkspaceProviderFactory(
            $componentsApiClient,
            $workspacesApiClient,
            $snowflakeKeyPairGenerator,
            $this->testLogger,
        );

        $component = $this->createMock(Component::class);

        $result = $factory->getWorkspaceStaging(
            'invalid-type',
            $component,
            'config-id',
            [],
            false,
            null,
        );

        self::assertInstanceOf(InvalidWorkspaceProvider::class, $result);
    }

    public function testExternallyManagedWorkspaceCredentials(): void
    {
        $componentsApiClient = $this->createMock(Components::class);

        // Mock the Workspaces API client to verify getWorkspace is called with the correct workspaceId
        $workspacesApiClient = $this->createMock(Workspaces::class);
        $workspacesApiClient->expects(self::once())
            ->method('getWorkspace')
            ->with(self::equalTo((int) 'workspace-id'))
            ->willReturn([
                'id' => 'workspace-id',
                'connection' => [
                    'backend' => 'snowflake',
                    'host' => 'host',
                    'database' => 'database',
                    'schema' => 'schema',
                    'warehouse' => 'warehouse',
                    'user' => 'user',
                ],
            ]);

        $snowflakeKeyPairGenerator = $this->createMock(SnowflakeKeypairGenerator::class);

        $factory = new WorkspaceProviderFactory(
            $componentsApiClient,
            $workspacesApiClient,
            $snowflakeKeyPairGenerator,
            $this->testLogger,
        );

        $component = $this->createMock(Component::class);

        $result = $factory->getWorkspaceStaging(
            AbstractStrategyFactory::WORKSPACE_SNOWFLAKE,
            $component,
            'config-id',
            [],
            false,
            new ExternallyManagedWorkspaceCredentials(
                id: 'workspace-id',
                type: 'snowflake',
                password: 'password',
                privateKey: null,
            ),
        );

        self::assertInstanceOf(ExistingWorkspaceProvider::class, $result);
        self::assertTrue($this->testLogHandler->hasNoticeThatContains('Using provided workspace "workspace-id"'));

        // Verify the workspace ID
        self::assertSame('workspace-id', $result->getWorkspaceId());

        // Get credentials and verify they match the expected values
        $credentials = $result->getCredentials();
        self::assertArrayHasKey('password', $credentials);
        self::assertSame('password', $credentials['password']);
    }

    public static function workspaceLoginTypeProvider(): iterable
    {
        yield 'snowflake with key-pair auth' => [
            'workspaceType' => AbstractStrategyFactory::WORKSPACE_SNOWFLAKE,
            'useKeyPairAuth' => true,
            'expectedWorkspaceOptions' => [
                'backend' => 'snowflake',
                'networkPolicy' => 'system',
                'readOnlyStorageAccess' => false,
                'loginType' => WorkspaceLoginType::SNOWFLAKE_SERVICE_KEYPAIR,
                'publicKey' => 'public-key',
            ],
        ];

        yield 'snowflake without key-pair auth' => [
            'workspaceType' => AbstractStrategyFactory::WORKSPACE_SNOWFLAKE,
            'useKeyPairAuth' => false,
            'expectedWorkspaceOptions' => [
                'backend' => 'snowflake',
                'networkPolicy' => 'system',
                'readOnlyStorageAccess' => false,
            ],
        ];

        yield 'other backend (bigquery)' => [
            'workspaceType' => AbstractStrategyFactory::WORKSPACE_BIGQUERY,
            'useKeyPairAuth' => true, // Even if true, should not use key-pair auth for non-snowflake
            'expectedWorkspaceOptions' => [
                'backend' => 'bigquery',
                'networkPolicy' => 'system',
                'readOnlyStorageAccess' => false,
            ],
        ];
    }

    /**
     * @param non-empty-string $workspaceType
     * @dataProvider workspaceLoginTypeProvider
     */
    public function testWorkspaceLoginType(
        string $workspaceType,
        bool $useKeyPairAuth,
        array $expectedWorkspaceOptions,
    ): void {
        $componentsApiClient = $this->createMock(Components::class);
        $componentsApiClient
            ->method('listConfigurationWorkspaces')
            ->willReturn([]);

        $workspacesApiClient = $this->createMock(Workspaces::class);

        $snowflakeKeyPairGenerator = $this->createMock(SnowflakeKeypairGenerator::class);
        $snowflakeKeyPairGenerator
            ->method('generateKeyPair')
            ->willReturn(new PemKeyCertificatePair(
                privateKey: 'private-key',
                publicKey: 'public-key',
            ));

        $factory = new WorkspaceProviderFactory(
            $componentsApiClient,
            $workspacesApiClient,
            $snowflakeKeyPairGenerator,
            $this->testLogger,
        );

        $component = $this->createMock(Component::class);
        $component->method('useSnowflakeKeyPairAuth')->willReturn($useKeyPairAuth);
        $component->method('getId')->willReturn('component-id');

        // Set expectations on createWorkspace to validate the login type
        // Map the workspace type to the correct backend type (without the "workspace-" prefix)
        $backendType = str_replace('workspace-', '', $workspaceType);

        // Expect createWorkspace to be called with the correct login type
        $workspacesApiClient->expects(self::once())
            ->method('createWorkspace')
            ->with($expectedWorkspaceOptions)
            ->willReturn([
                'id' => 123,
                'connection' => [
                    'backend' => $backendType,
                    // for snowflake
                    'host' => 'host',
                    'warehouse' => 'warehouse',
                    'database' => 'database',
                    'schema' => 'schema',
                    'user' => 'user',
                    'password' => 'password',
                    // for bigquery
                    'region' => 'eu-central1',
                    'credentials' => [
                        'key' => 'val',
                    ],
                ],
            ]);

        $result = $factory->getWorkspaceStaging(
            $workspaceType,
            $component,
            null, // Don't provide a configId to avoid going through the persistent workspace path
            [],
            false,
            null,
        );

        self::assertInstanceOf(NewWorkspaceProvider::class, $result);
        self::assertTrue($this->testLogHandler->hasNoticeThatContains('Creating a new ephemeral workspace'));

        // Trigger the createWorkspace method by calling getCredentials
        $result->getCredentials();
    }

    public function testReadonlyRoleParameter(): void
    {
        $componentsApiClient = $this->createMock(Components::class);
        $workspacesApiClient = $this->createMock(Workspaces::class);
        $snowflakeKeyPairGenerator = $this->createMock(SnowflakeKeypairGenerator::class);

        $factory = new WorkspaceProviderFactory(
            $componentsApiClient,
            $workspacesApiClient,
            $snowflakeKeyPairGenerator,
            $this->testLogger,
        );

        $component = $this->createMock(Component::class);
        $component->method('getId')->willReturn('component-id');

        $result = $factory->getWorkspaceStaging(
            AbstractStrategyFactory::WORKSPACE_SNOWFLAKE,
            $component,
            null,
            [],
            true, // Set useReadonlyRole to true
            null,
        );

        self::assertInstanceOf(NewWorkspaceProvider::class, $result);
        self::assertTrue($this->testLogHandler->hasNoticeThatContains('Creating a new readonly ephemeral workspace'));
    }
}
