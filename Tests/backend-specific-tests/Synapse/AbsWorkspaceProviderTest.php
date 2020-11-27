<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\DataLoader\WorkspaceProvider;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ListComponentConfigurationsOptions;
use Keboola\StorageApi\Options\Components\ListConfigurationWorkspacesOptions;
use Keboola\StorageApi\Workspaces;
use PHPUnit\Framework\TestCase;

class AbsWorkspaceProviderTest extends TestCase
{
    /**
     * @var Client
     */
    private $client;

    public function setUp()
    {
        parent::setUp();
        if (!RUN_SYNAPSE_TESTS) {
            return;
        }
        $this->client = new Client([
            'url' => STORAGE_API_URL_SYNAPSE,
            'token' => STORAGE_API_TOKEN_SYNAPSE,
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

    public function testAbsWorkspaceProvider()
    {
        if (!RUN_SYNAPSE_TESTS) {
            self:self::markTestSkipped('Synapse test is disabled.');
        }
        $type = 'abs';
        $components = new Components($this->client);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.runner-workspace-test');
        $configuration->setName('runner-tests');
        $configuration->setConfigurationId('runner-test-configuration');
        $components->addConfiguration($configuration);
        $provider = new WorkspaceProvider(
            $this->client,
            'keboola.runner-workspace-test',
            'runner-test-configuration'
        );
        $workspaceId = $provider->getWorkspaceId($type);
        $workspaces = new Workspaces($this->client);
        $workspace = $workspaces->getWorkspace($workspaceId);
        self::assertEquals('keboola.runner-workspace-test', $workspace['component']);
        self::assertEquals('runner-test-configuration', $workspace['configurationId']);
        self::assertEquals($type, $workspace['connection']['backend']);
        self::assertEquals(['connectionString', 'container'], array_keys($provider->getCredentials($type)));
    }
}
