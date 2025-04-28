<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\BackendTests\ABS;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Docker\Runner\DataLoader\WorkspaceProviderFactory;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\KeyGenerator\PemKeyCertificateGenerator;
use Keboola\StagingProvider\Provider\SnowflakeKeypairGenerator;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class DataLoaderPersistentABSWorkspaceTest extends BaseDataLoaderTest
{
    public function testAbsWorkspaceNoConfig()
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
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => $this->clientWrapper->getClientOptionsReadOnly()->getUrl(),
                    'token' => $this->clientWrapper->getClientOptionsReadOnly()->getToken(),
                ],
            ])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects($this->once())->method('apiDelete')->with(self::stringContains('workspaces/'));
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getTableAndFileStorageClient')->willReturn($client);
        $clientWrapper->method('getBranchClient')->willReturn($client);

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component),
            new OutputFilter(10000),
        );

        $dataLoader->storeOutput();

        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['container', 'connectionString'], array_keys($credentials));
        self::assertStringStartsWith('BlobEndpoint=https://', $credentials['connectionString']);
        self::assertTrue($logsHandler->hasNoticeThatContains('Created a new ephemeral workspace.'));
        $dataLoader->cleanWorkspace();
        // checked in mock that the workspace is deleted
    }

    public function testAbsWorkspaceConfigNoWorkspace()
    {
        $component = new Component([
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => $this->clientWrapper->getClientOptionsReadOnly()->getUrl(),
                    'token' => $this->clientWrapper->getClientOptionsReadOnly()->getToken(),
                ],
            ])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects(self::never())->method('apiDelete');
        $configuration = new Configuration();
        $configuration->setConfiguration([]);
        $configuration->setName('test-dataloader');
        $configuration->setComponentId('keboola.runner-config-test');
        $componentsApi = new Components($this->clientWrapper->getBranchClient());
        $configurationId = $componentsApi->addConfiguration($configuration)['id'];
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getTableAndFileStorageClient')->willReturn($client);
        $clientWrapper->method('getBranchClient')->willReturn($client);

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component, $configurationId),
            new OutputFilter(10000),
        );

        $dataLoader->storeOutput();

        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['container', 'connectionString'], array_keys($credentials));
        self::assertStringStartsWith('BlobEndpoint=https://', $credentials['connectionString']);
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId),
        );
        self::assertCount(1, $workspaces);
        $dataLoader->cleanWorkspace();
        // double check that workspace still exists
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId),
        );
        self::assertCount(1, $workspaces);
        // cleanup after the test
        $workspacesApi = new Workspaces($this->clientWrapper->getBranchClient());
        $workspacesApi->deleteWorkspace($workspaces[0]['id'], [], true);
        self::assertTrue($logsHandler->hasInfoThatContains('Created a new persistent workspace'));
    }

    public function testAbsWorkspaceConfigOneWorkspace()
    {
        $component = new Component([
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => $this->clientWrapper->getClientOptionsReadOnly()->getUrl(),
                    'token' => $this->clientWrapper->getClientOptionsReadOnly()->getToken(),
                ],
            ])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects(self::never())->method('apiDelete');
        $configuration = new Configuration();
        $configuration->setConfiguration([]);
        $configuration->setName('test-dataloader');
        $configuration->setComponentId('keboola.runner-config-test');
        $componentsApi = new Components($this->clientWrapper->getBranchClient());
        $configurationId = $componentsApi->addConfiguration($configuration)['id'];
        $workspace = $componentsApi->createConfigurationWorkspace(
            'keboola.runner-config-test',
            $configurationId,
            ['backend' => 'abs'],
            true,
        );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getTableAndFileStorageClient')->willReturn($client);
        $clientWrapper->method('getBranchClient')->willReturn($client);

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component, $configurationId),
            new OutputFilter(10000),
        );

        $dataLoader->storeOutput();

        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['container', 'connectionString'], array_keys($credentials));
        self::assertStringStartsWith('BlobEndpoint=https://', $credentials['connectionString']);
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId),
        );
        self::assertCount(1, $workspaces);
        $dataLoader->cleanWorkspace();
        // double check that workspace still exists
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId),
        );
        self::assertCount(1, $workspaces);
        // and it must be the same workspace we created beforehand
        self::assertEquals($workspace['id'], $workspaces[0]['id']);
        // cleanup after the test
        $workspacesApi = new Workspaces($this->clientWrapper->getBranchClient());
        $workspacesApi->deleteWorkspace($workspaces[0]['id'], [], true);
        self::assertTrue($logsHandler->hasInfoThatContains(
            sprintf('Reusing persistent workspace "%s".', $workspace['id']),
        ));
    }

    public function testAbsWorkspaceConfigMultipleWorkspace()
    {
        $component = new Component([
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
                'staging-storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = $this->getMockBuilder(BranchAwareClient::class)
            ->setConstructorArgs([
                'default',
                [
                    'url' => $this->clientWrapper->getClientOptionsReadOnly()->getUrl(),
                    'token' => $this->clientWrapper->getClientOptionsReadOnly()->getToken(),
                ],
            ])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects(self::never())->method('apiDelete');

        $configuration = new Configuration();
        $configuration->setConfiguration([]);
        $configuration->setName('test-dataloader');
        $configuration->setComponentId('keboola.runner-config-test');
        $componentsApi = new Components($this->clientWrapper->getBranchClient());
        $configurationId = $componentsApi->addConfiguration($configuration)['id'];
        $workspace1 = $componentsApi->createConfigurationWorkspace(
            'keboola.runner-config-test',
            $configurationId,
            ['backend' => 'abs'],
            true,
        );
        $workspace2 = $componentsApi->createConfigurationWorkspace(
            'keboola.runner-config-test',
            $configurationId,
            ['backend' => 'abs'],
            true,
        );

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getBranchClient')->willReturn($client);

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        try {
            $componentsApiClient = new Components($clientWrapper->getBranchClient());
            $workspacesApiClient = new Workspaces($clientWrapper->getBranchClient());

            $workspaceFactory = new WorkspaceProviderFactory(
                $componentsApiClient,
                $workspacesApiClient,
                new SnowflakeKeypairGenerator(new PemKeyCertificateGenerator()),
                $logger,
            );
            $workspaceFactory->getWorkspaceStaging(
                'workspace-abs',
                $component,
                $configurationId,
                [],
                null,
                null,
            );
        } catch (ApplicationException $e) {
            self::assertEquals(
                sprintf(
                    'Multiple workspaces (total 2) found (IDs: %s, %s) for configuration "%s" of component "%s".',
                    $workspace1['id'],
                    $workspace2['id'],
                    $configurationId,
                    'keboola.runner-config-test',
                ),
                $e->getMessage(),
            );
            $workspacesApi = new Workspaces($this->clientWrapper->getBranchClient());
            $workspacesApi->deleteWorkspace($workspace1['id'], [], true);
            $workspacesApi->deleteWorkspace($workspace2['id'], [], true);
        }
    }
}
