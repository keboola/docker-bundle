<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\Environment;
use Keboola\StorageApi\Client;

class EnvironmentTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Client
     */
    protected $client;

    public function setUp()
    {
        parent::setUp();
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            "token" => STORAGE_API_TOKEN,
        ]);
    }

    public function testExecutorEnvs()
    {
        $environment = new Environment($this->client, 'config-test-id', false, false);
        $envs = $environment->getEnvironmentVariables([]);
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayNotHasKey('KBC_TOKEN', $envs);
        $this->assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENID', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardToken()
    {
        $environment = new Environment($this->client, 'config-test-id', true, false);
        $envs = $environment->getEnvironmentVariables([]);
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayHasKey('KBC_TOKEN', $envs);
        $this->assertEquals($envs['KBC_TOKEN'], STORAGE_API_TOKEN);
        $this->assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENID', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardTokenAndDetails()
    {
        $environment = new Environment($this->client, 'config-test-id', true, true);
        $envs = $environment->getEnvironmentVariables([]);
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayHasKey('KBC_TOKEN', $envs);
        $this->assertEquals($envs['KBC_TOKEN'], STORAGE_API_TOKEN);
        $this->assertArrayHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayHasKey('KBC_TOKENID', $envs);
        $this->assertArrayHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardDetails()
    {
        $environment = new Environment($this->client, 'config-test-id', false, true);
        $envs = $environment->getEnvironmentVariables([]);
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayNotHasKey('KBC_TOKEN', $envs);
        $this->assertArrayHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayHasKey('KBC_TOKENID', $envs);
        $this->assertArrayHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorVariables()
    {
        $environment = new Environment($this->client, 'config-test-id', true, true);
        $envs = $environment->getEnvironmentVariables(['myVariable' => 'fooBar', 'KBC_CONFIGID' => 'barFoo']);
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertArrayHasKey('KBC_PARAMETER_MYVARIABLE', $envs);
        $this->assertEquals($envs['KBC_PARAMETER_MYVARIABLE'], 'fooBar');
        $this->assertArrayHasKey('KBC_PARAMETER_KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_PARAMETER_KBC_CONFIGID'], 'barFoo');
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayHasKey('KBC_TOKEN', $envs);
        $this->assertEquals($envs['KBC_TOKEN'], STORAGE_API_TOKEN);
        $this->assertArrayHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayHasKey('KBC_TOKENID', $envs);
        $this->assertArrayHasKey('KBC_TOKENDESC', $envs);
    }
}
