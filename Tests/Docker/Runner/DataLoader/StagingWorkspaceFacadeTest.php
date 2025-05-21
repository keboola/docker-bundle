<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Runner\DataLoader\StagingWorkspaceFacade;
use Keboola\StagingProvider\Workspace\WorkspaceProvider;
use Keboola\StagingProvider\Workspace\WorkspaceWithCredentialsInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class StagingWorkspaceFacadeTest extends TestCase
{
    private readonly LoggerInterface $logger;
    private readonly TestHandler $logsHandler;

    public function setUp(): void
    {
        $this->logsHandler = new TestHandler();
        $this->logger = new Logger('test', [$this->logsHandler]);
    }

    public function testGetWorkspaceId(): void
    {
        $workspaceProvider = $this->createMock(WorkspaceProvider::class);

        $workspace = $this->createMock(WorkspaceWithCredentialsInterface::class);
        $workspace->expects(self::once())
            ->method('getWorkspaceId')
            ->willReturn('test-workspace-id');

        $facade = new StagingWorkspaceFacade(
            $workspaceProvider,
            $this->logger,
            $workspace,
            false,
        );

        self::assertEquals('test-workspace-id', $facade->getWorkspaceId());
    }

    public function testGetBackendSize(): void
    {
        $workspaceProvider = $this->createMock(WorkspaceProvider::class);

        $workspace = $this->createMock(WorkspaceWithCredentialsInterface::class);
        $workspace->expects(self::once())
            ->method('getBackendSize')
            ->willReturn('small');

        $facade = new StagingWorkspaceFacade(
            $workspaceProvider,
            $this->logger,
            $workspace,
            false,
        );

        self::assertEquals('small', $facade->getBackendSize());
    }

    public function testGetCredentials(): void
    {
        $workspaceProvider = $this->createMock(WorkspaceProvider::class);

        $workspace = $this->createMock(WorkspaceWithCredentialsInterface::class);
        $credentials = ['username' => 'test-user', 'password' => 'test-password'];

        $workspace->expects(self::once())
            ->method('getCredentials')
            ->willReturn($credentials);

        $facade = new StagingWorkspaceFacade(
            $workspaceProvider,
            $this->logger,
            $workspace,
            false,
        );

        self::assertEquals($credentials, $facade->getCredentials());
    }

    public function testCleanupWithReusableWorkspace(): void
    {
        $workspaceProvider = $this->createMock(WorkspaceProvider::class);
        $workspaceProvider->expects(self::never())
            ->method('cleanupWorkspace')
        ;

        $workspace = $this->createMock(WorkspaceWithCredentialsInterface::class);

        $facade = new StagingWorkspaceFacade(
            $workspaceProvider,
            $this->logger,
            $workspace,
            isReusable: true,
        );

        $facade->cleanup();
    }

    public function testCleanupWithNonReusableWorkspace(): void
    {
        $workspaceProvider = $this->createMock(WorkspaceProvider::class);
        $workspaceProvider->expects(self::once())
            ->method('cleanupWorkspace')
            ->with('test-workspace-id');

        $workspace = $this->createMock(WorkspaceWithCredentialsInterface::class);
        $workspace->expects(self::once())
            ->method('getWorkspaceId')
            ->willReturn('test-workspace-id');

        $facade = new StagingWorkspaceFacade(
            $workspaceProvider,
            $this->logger,
            $workspace,
            isReusable: false,
        );

        $facade->cleanup();
    }

    public function testCleanupWithException(): void
    {
        $exception = new RuntimeException('Cleanup failed');

        $workspaceProvider = $this->createMock(WorkspaceProvider::class);
        $workspaceProvider->expects(self::once())
            ->method('cleanupWorkspace')
            ->with('test-workspace-id')
            ->willThrowException($exception);

        $workspace = $this->createMock(WorkspaceWithCredentialsInterface::class);
        $workspace->expects(self::once())
            ->method('getWorkspaceId')
            ->willReturn('test-workspace-id');

        $facade = new StagingWorkspaceFacade(
            $workspaceProvider,
            $this->logger,
            $workspace,
            isReusable: false,
        );

        $facade->cleanup();

        self::assertTrue($this->logsHandler->hasError([
            'message' => 'Failed to cleanup workspace: Cleanup failed',
            'context' => [
                'exception' => $exception,
            ],
        ]));
    }
}
