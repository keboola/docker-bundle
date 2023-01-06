<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoader;
use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
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

class DataLoaderPersistentRedshiftWorkspaceTest extends BaseDataLoaderTest
{
    private Client $client;

    public function setUp(): void
    {
        parent::setUp();

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $this->metadata = new Metadata($this->client);
        $this->temp = new Temp();
        $this->temp->initRunFolder();
        $this->workingDir = new WorkingDirectory($this->temp->getTmpFolder(), new NullLogger());
        $this->workingDir->createWorkingDir();
    }

    public function testRedshiftWorkspaceNoConfig()
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
                    'input' => 'workspace-redshift',
                    'output' => 'workspace-redshift',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
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
        self::assertEquals(
            ['host', 'warehouse', 'database', 'schema', 'user', 'password'],
            array_keys($credentials)
        );
        self::assertStringEndsWith('redshift.amazonaws.com', $credentials['host']);
        self::assertTrue($logger->hasNoticeThatContains('Created a new ephemeral workspace.'));
        $dataLoader->cleanWorkspace();
        // checked in mock that the workspace is deleted
    }

    public function testRedshiftWorkspaceConfigNoWorkspace()
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
                    'input' => 'workspace-redshift',
                    'output' => 'workspace-redshift',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
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
        self::assertEquals(
            ['host', 'warehouse', 'database', 'schema', 'user', 'password'],
            array_keys($credentials)
        );
        self::assertStringEndsWith('redshift.amazonaws.com', $credentials['host']);
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
        $workspacesApi->deleteWorkspace($workspaces[0]['id']);
        self::assertTrue($logger->hasInfoThatContains('Created a new persistent workspace'));
    }

    public function testRedshiftWorkspaceConfigOneWorkspace()
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
                    'input' => 'workspace-redshift',
                    'output' => 'workspace-redshift',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
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
            ['backend' => 'redshift'],
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
        self::assertEquals(
            ['host', 'warehouse', 'database', 'schema', 'user', 'password'],
            array_keys($credentials)
        );
        self::assertStringEndsWith('redshift.amazonaws.com', $credentials['host']);
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
        $workspacesApi->deleteWorkspace($workspaces[0]['id']);
        self::assertTrue($logger->hasInfoThatContains(
            sprintf('Reusing persistent workspace "%s".', $workspace['id'])
        ));
    }

    public function testRedshiftWorkspaceConfigMultipleWorkspaceVariant2()
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
                    'input' => 'workspace-redshift',
                    'output' => 'workspace-redshift',
                ],
            ],
        ]);
        $client = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN,
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
            ['backend' => 'redshift'],
            true
        );
        $workspace2 = $componentsApi->createConfigurationWorkspace(
            'keboola.runner-config-test',
            $configurationId,
            ['backend' => 'redshift'],
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

        $this->assertTrue($logger->hasWarning(
            sprintf(
                'Multiple workspaces (total 2) found (IDs: %s) for configuration "%s" of component "%s", using "%s".',
                implode(',', [$workspace1['id'], $workspace2['id']]),
                $configurationId,
                'keboola.runner-config-test',
                $workspace1['id']
            )
        ));
        $credentials = $dataLoader->getWorkspaceCredentials();
        self::assertEquals(
            ['host', 'warehouse', 'database', 'schema', 'user', 'password'],
            array_keys($credentials)
        );
        self::assertStringEndsWith('redshift.amazonaws.com', $credentials['host']);
        $workspaces = $componentsApi->listConfigurationWorkspaces(
            (new ListConfigurationWorkspacesOptions())
                ->setComponentId('keboola.runner-config-test')
                ->setConfigurationId($configurationId)
        );
        self::assertCount(2, $workspaces);

        $workspacesApi = new Workspaces($this->client);
        $workspacesApi->deleteWorkspace($workspace1['id']);
        $workspacesApi->deleteWorkspace($workspace2['id']);
    }
}
