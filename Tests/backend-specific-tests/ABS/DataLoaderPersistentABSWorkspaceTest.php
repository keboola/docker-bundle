<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Docker\Runner\DataLoader\WorkspaceProviderFactoryFactory;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Tests\BaseDataLoaderTest;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;

class DataLoaderPersistentABSWorkspaceTest extends BaseDataLoaderTest
{
    use BackendAssertsTrait;

    private Client $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new Client([
            'url' => STORAGE_API_URL_SYNAPSE,
            'token' => STORAGE_API_TOKEN_SYNAPSE,
        ]);

        self::assertDefaultTableBackend('synapse', $this->client);

        $this->metadata = new Metadata($this->client);
        $this->temp = new Temp();
        $this->temp->initRunFolder();
        $this->workingDir = new WorkingDirectory($this->temp->getTmpFolder(), new NullLogger());
        $this->workingDir->createWorkingDir();
    }

    public function testAbsWorkspaceNoConfig()
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
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL_SYNAPSE,
                'token' => STORAGE_API_TOKEN_SYNAPSE,
            ]])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects($this->once())->method('apiDelete')->with(self::stringContains('workspaces/'));
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($client);
        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component),
            new OutputFilter(10000)
        );

        $dataLoader->storeOutput();

        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['connectionString', 'container'], array_keys($credentials));
        self::assertStringStartsWith('BlobEndpoint=https://', $credentials['connectionString']);
        self::assertTrue($logger->hasNoticeThatContains('Created a new ephemeral workspace.'));
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
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL_SYNAPSE,
                'token' => STORAGE_API_TOKEN_SYNAPSE,
            ]])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects(self::never())->method('apiDelete');
        $configuration = new Configuration();
        $configuration->setConfiguration([]);
        $configuration->setName('test-dataloader');
        $configuration->setComponentId('keboola.runner-config-test');
        $componentsApi = new Components($this->client);
        $configurationId = $componentsApi->addConfiguration($configuration)['id'];
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($client);
        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component, $configurationId),
            new OutputFilter(10000)
        );

        $dataLoader->storeOutput();

        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['connectionString', 'container'], array_keys($credentials));
        self::assertStringStartsWith('BlobEndpoint=https://', $credentials['connectionString']);
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId)
        );
        self::assertCount(1, $workspaces);
        $dataLoader->cleanWorkspace();
        // double check that workspace still exists
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId)
        );
        self::assertCount(1, $workspaces);
        // cleanup after the test
        $workspacesApi = new Workspaces($this->client);
        $workspacesApi->deleteWorkspace($workspaces[0]['id'], [], true);
        self::assertTrue($logger->hasInfoThatContains('Created a new persistent workspace'));
    }

    public function testAbsWorkspaceConfigOneWorkspace()
    {
        $component = new Component([
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL_SYNAPSE,
                'token' => STORAGE_API_TOKEN_SYNAPSE,
            ]])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects(self::never())->method('apiDelete');
        $configuration = new Configuration();
        $configuration->setConfiguration([]);
        $configuration->setName('test-dataloader');
        $configuration->setComponentId('keboola.runner-config-test');
        $componentsApi = new Components($this->client);
        $configurationId = $componentsApi->addConfiguration($configuration)['id'];
        $workspace = $componentsApi->createConfigurationWorkspace(
            'keboola.runner-config-test',
            $configurationId,
            ['backend' => 'abs'],
            true
        );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($client);
        $logger = new TestLogger();
        $dataLoader = new DataLoader(
            $clientWrapper,
            $logger,
            $this->workingDir->getDataDir(),
            new JobDefinition([], $component, $configurationId),
            new OutputFilter(10000)
        );

        $dataLoader->storeOutput();

        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(['connectionString', 'container'], array_keys($credentials));
        self::assertStringStartsWith('BlobEndpoint=https://', $credentials['connectionString']);
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId)
        );
        self::assertCount(1, $workspaces);
        $dataLoader->cleanWorkspace();
        // double check that workspace still exists
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId)
        );
        self::assertCount(1, $workspaces);
        // and it must be the same workspace we created beforehand
        self::assertEquals($workspace['id'], $workspaces[0]['id']);
        // cleanup after the test
        $workspacesApi = new Workspaces($this->client);
        $workspacesApi->deleteWorkspace($workspaces[0]['id'], [], true);
        self::assertTrue($logger->hasInfoThatContains(
            sprintf('Reusing persistent workspace "%s".', $workspace['id'])
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
                    'tag' => 'master'
                ],
                'staging-storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL_SYNAPSE,
                'token' => STORAGE_API_TOKEN_SYNAPSE,
            ]])
            ->setMethods(['apiDelete'])
            ->getMock();
        $client->expects(self::never())->method('apiDelete');

        $configuration = new Configuration();
        $configuration->setConfiguration([]);
        $configuration->setName('test-dataloader');
        $configuration->setComponentId('keboola.runner-config-test');
        $componentsApi = new Components($this->client);
        $configurationId = $componentsApi->addConfiguration($configuration)['id'];
        $workspace1 = $componentsApi->createConfigurationWorkspace(
            'keboola.runner-config-test',
            $configurationId,
            ['backend' => 'abs'],
            true
        );
        $workspace2 = $componentsApi->createConfigurationWorkspace(
            'keboola.runner-config-test',
            $configurationId,
            ['backend' => 'abs'],
            true
        );

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($client);
        $clientWrapper->method('getBranchClientIfAvailable')->willReturn($client);
        $logger = new TestLogger();
        try {
            $workspaceFactory = new WorkspaceProviderFactoryFactory(
                new Components($clientWrapper->getBranchClientIfAvailable()),
                new Workspaces($clientWrapper->getBranchClientIfAvailable()),
                $logger
            );
            $workspaceFactory->getWorkspaceProviderFactory(
                'workspace-abs',
                $component,
                $configurationId,
                [],
                null
            );
        } catch (ApplicationException $e) {
            self::assertEquals(
                sprintf(
                    'Multiple workspaces (total 2) found (IDs: %s, %s) for configuration "%s" of component "%s".',
                    $workspace1['id'],
                    $workspace2['id'],
                    $configurationId,
                    'keboola.runner-config-test'
                ),
                $e->getMessage()
            );
            $workspacesApi = new Workspaces($this->client);
            $workspacesApi->deleteWorkspace($workspace1['id'], [], true);
            $workspacesApi->deleteWorkspace($workspace2['id'], [], true);
        }
    }
}
