<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Runner\DataLoader\ExternallyManagedWorkspaceCredentials;
use Keboola\DockerBundle\Docker\Runner\DataLoader\WorkspaceProviderFactory;
use Keboola\StagingProvider\Staging\StagingType;
use Keboola\StagingProvider\Workspace\Configuration\NetworkPolicy;
use Keboola\StagingProvider\Workspace\Configuration\WorkspaceCredentials;
use Keboola\StagingProvider\Workspace\Credentials\CredentialsProvider;
use Keboola\StagingProvider\Workspace\ProviderConfig\ExistingWorkspaceConfig;
use Keboola\StagingProvider\Workspace\ProviderConfig\NewWorkspaceConfig;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\WorkspaceLoginType;
use Keboola\StorageApiBranch\StorageApiToken;
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

    public function testExternallyManagedWorkspaceCredentials(): void
    {
        $component = $this->createMock(Component::class);
        $storageApiToken = $this->createMock(StorageApiToken::class);

        $factory = new WorkspaceProviderFactory(
            $this->testLogger,
        );

        $result = $factory->getWorkspaceProviderConfig(
            $storageApiToken,
            StagingType::WorkspaceSnowflake,
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

        self::assertEquals(
            new ExistingWorkspaceConfig(
                workspaceId: 'workspace-id',
                credentials: new CredentialsProvider(new WorkspaceCredentials([
                    'password' => 'password',
                    'privateKey' => null,
                ])),
            ),
            $result,
        );

        self::assertTrue($this->testLogHandler->hasNoticeThatContains('Using provided workspace "workspace-id"'));
    }

    public static function workspaceLoginTypeProvider(): iterable
    {
        yield 'snowflake with key-pair auth' => [
            'stagingType' => StagingType::WorkspaceSnowflake,
            'useKeyPairAuth' => true,
            'expectedLoginType' => WorkspaceLoginType::SNOWFLAKE_SERVICE_KEYPAIR,
        ];

        yield 'snowflake without key-pair auth' => [
            'stagingType' => StagingType::WorkspaceSnowflake,
            'useKeyPairAuth' => false,
            'expectedLoginType' => null,
        ];

        yield 'other backend (bigquery)' => [
            'stagingType' => StagingType::WorkspaceBigquery,
            'useKeyPairAuth' => true, // Even if true by a mistake, should not use key-pair auth for non-snowflake
            'expectedLoginType' => null,
        ];
    }

    /** @dataProvider workspaceLoginTypeProvider */
    public function testWorkspaceLoginType(
        StagingType $stagingType,
        bool $useKeyPairAuth,
        ?WorkspaceLoginType $expectedWorkspaceLoginType,
    ): void {
        $componentsApiClient = $this->createMock(Components::class);
        $componentsApiClient
            ->method('listConfigurationWorkspaces')
            ->willReturn([]);

        $factory = new WorkspaceProviderFactory(
            $this->testLogger,
        );

        $component = $this->createMock(Component::class);
        $component->method('useSnowflakeKeyPairAuth')->willReturn($useKeyPairAuth);
        $component->method('getId')->willReturn('component-id');

        $storageApiToken = $this->createMock(StorageApiToken::class);
        $storageApiToken->method('getTokenInfo')->willReturn([
            'owner' => [
                // won't be both true in reality, it's faked for the test
                'hasSnowflake' => true,
                'hasBigquery' => true,
            ],
        ]);

        $result = $factory->getWorkspaceProviderConfig(
            $storageApiToken,
            $stagingType,
            $component,
            null, // Don't provide a configId to avoid going through the persistent workspace path
            [],
            false,
            null,
        );

        self::assertEquals(
            new NewWorkspaceConfig(
                storageApiToken: $storageApiToken,
                stagingType: $stagingType,
                componentId: $component->getId(),
                configId: null,
                size: null,
                useReadonlyRole: false,
                networkPolicy: NetworkPolicy::SYSTEM,
                loginType: $expectedWorkspaceLoginType,
                isReusable: false,
            ),
            $result,
        );
        self::assertTrue($this->testLogHandler->hasNoticeThatContains('Creating a new ephemeral workspace'));
    }

    public function testReadonlyRoleParameter(): void
    {
        $factory = new WorkspaceProviderFactory(
            $this->testLogger,
        );

        $component = $this->createMock(Component::class);
        $component->method('getId')->willReturn('component-id');

        $storageApiToken = new StorageApiToken([
            'owner' => [
                'hasSnowflake' => true,
            ],
        ], '');

        $result = $factory->getWorkspaceProviderConfig(
            $storageApiToken,
            StagingType::WorkspaceSnowflake,
            $component,
            null,
            [],
            true, // Set useReadonlyRole to true
            null,
        );

        self::assertEquals(
            new NewWorkspaceConfig(
                storageApiToken: $storageApiToken,
                stagingType: StagingType::WorkspaceSnowflake,
                componentId: $component->getId(),
                configId: null,
                size: null,
                useReadonlyRole: true,
                networkPolicy: NetworkPolicy::SYSTEM,
                loginType: null,
            ),
            $result,
        );
        self::assertTrue($this->testLogHandler->hasNoticeThatContains('Creating a new readonly ephemeral workspace'));
    }
}
