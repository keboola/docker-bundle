<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\DataLoader\WorkspaceProvider;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use PHPUnit\Framework\TestCase;

class WorkspaceProviderTest extends TestCase
{
    /**
     * @var Client
     */
    private $client;

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $components = new Components($this->client);
        $workspaces = new Workspaces($this->client);
        $options = new ListComponentConfigurationsOptions();
        $options->setComponentId('keboola.runner-workspace-test');
        foreach ($components->listComponentConfigurations($options) as $configuration) {
            $wOptions = new ListConfigurationWorkspacesOptions();
            $wOptions->setComponentId('keboola.runner-workspace-test');
            $wOptions->setConfigurationId($configuration['id']);
            foreach ($components->listConfigurationWorkspaces($wOptions) as $workspace) {
                $workspaces->deleteWorkspace($workspace['id']);
            }
            $components->deleteConfiguration('keboola.runner-workspace-test', $configuration['id']);
        }
    }

    /**
     * @dataProvider workspaceTypeProvider
     * @param string $type
     */
    public function testWorkspaceProvider($type)
    {
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider($this->client, 'keboola.runner-workspace-test', 'runner-test-configuration');
        $workspaceId = $provider->getWorkspaceId($type);
        $workspaces = new Workspaces($this->client);
        $workspace = $workspaces->getWorkspace($workspaceId);
        self::assertEquals('keboola.runner-workspace-test', $workspace['component']);
        self::assertEquals('runner-test-configuration', $workspace['configurationId']);
        self::assertArrayHasKey('host', $workspace['connection']);
        self::assertArrayHasKey('database', $workspace['connection']);
        self::assertArrayHasKey('user', $workspace['connection']);
        self::assertEquals($type, $workspace['connection']['backend']);
        self::assertEquals(['host', 'warehouse', 'database', 'schema', 'user', 'password'], array_keys($provider->getCredentials($type)));
    }

    public function workspaceTypeProvider()
    {
        return [
            'redshift' => ['redshift'],
            'snowflake' => ['snowflake'],
        ];
    }

    public function testInvalidType()
    {
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider($this->client, 'keboola.runner-workspace-test', 'runner-test-configuration');
        self::expectException(UserException::class);
        self::expectExceptionMessage('Workspace type must be one of redshift, snowflake');
        $provider->getWorkspaceId('invalid');
    }

    public function testEmptyConfiguration()
    {
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider($this->client, 'keboola.runner-workspace-test', null);
        $workspaceId = $provider->getWorkspaceId('snowflake');
        $workspaces = new Workspaces($this->client);
        $workspace = $workspaces->getWorkspace($workspaceId);
        self::assertEquals(null, $workspace['component']);
        self::assertEquals(null, $workspace['configurationId']);
        self::assertArrayHasKey('host', $workspace['connection']);
        self::assertArrayHasKey('database', $workspace['connection']);
        self::assertArrayHasKey('user', $workspace['connection']);
        self::assertEquals('snowflake', $workspace['connection']['backend']);
    }

    public function testLazyLoad()
    {
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider($this->client, 'keboola.runner-workspace-test', 'runner-test-configuration');
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-test');
        $options->setConfigurationId('runner-test-configuration');
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
        $provider->getWorkspaceId('snowflake');
        self::assertCount(1, $components->listConfigurationWorkspaces($options));
    }

    public function testCleanup()
    {
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider($this->client, 'keboola.runner-workspace-test', 'runner-test-configuration');
        $options = new ListConfigurationWorkspacesOptions();
        $options->setComponentId('keboola.runner-workspace-test');
        $options->setConfigurationId('runner-test-configuration');
        $provider->getWorkspaceId('snowflake');
        self::assertCount(1, $components->listConfigurationWorkspaces($options));
        $provider->cleanup();
        self::assertCount(0, $components->listConfigurationWorkspaces($options));
    }

    public function testMultipleTypes()
    {
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider($this->client, 'keboola.runner-workspace-test', 'runner-test-configuration');
        $provider->getWorkspaceId('snowflake');
        self::expectException(UserException::class);
        self::expectExceptionMessage('Multiple workspaces are not supported');
        $provider->getWorkspaceId('redshift');
    }

    public function testMultipleTypesCredentials()
    {
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider($this->client, 'keboola.runner-workspace-test', 'runner-test-configuration');
        $provider->getWorkspaceId('snowflake');
        self::expectException(UserException::class);
        self::expectExceptionMessage('Multiple workspaces are not supported');
        $provider->getCredentials('redshift');
    }
}
