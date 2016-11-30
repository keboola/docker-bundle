<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\Environment;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;

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
        $component = [
            'forward_token' => false,
            'forward_token_details' => false,
            'inject_environment' => false,
        ];
        $environment = new Environment('config-test-id', $component, [], $this->client->verifyToken(), 123, STORAGE_API_URL);
        $envs = $environment->getEnvironmentVariables();
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayNotHasKey('KBC_TOKEN', $envs);
        $this->assertArrayNotHasKey('KBC_URL', $envs);
        $this->assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENID', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardToken()
    {
        $component = [
            'forward_token' => true,
            'forward_token_details' => false,
            'inject_environment' => false,
        ];
        $environment = new Environment('config-test-id', $component, [], $this->client->verifyToken(), 123, STORAGE_API_URL);
        $envs = $environment->getEnvironmentVariables();
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayHasKey('KBC_TOKEN', $envs);
        $this->assertArrayHasKey('KBC_URL', $envs);
        $this->assertEquals($envs['KBC_TOKEN'], STORAGE_API_TOKEN);
        $this->assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENID', $envs);
        $this->assertArrayNotHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardTokenAndDetails()
    {
        $component = [
            'forward_token' => true,
            'forward_token_details' => true,
            'inject_environment' => false,
        ];
        $environment = new Environment('config-test-id', $component, [], $this->client->verifyToken(), 123, STORAGE_API_URL);
        $envs = $environment->getEnvironmentVariables();
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayHasKey('KBC_TOKEN', $envs);
        $this->assertArrayHasKey('KBC_URL', $envs);
        $this->assertEquals($envs['KBC_TOKEN'], STORAGE_API_TOKEN);
        $this->assertArrayHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayHasKey('KBC_TOKENID', $envs);
        $this->assertArrayHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardDetails()
    {
        $component = [
            'forward_token' => false,
            'forward_token_details' => true,
            'inject_environment' => false,
        ];
        $parameters = [
            'myVariable' => 'fooBar',
            'KBC_CONFIGID' => 'barFoo',
        ];
        $environment = new Environment('config-test-id', $component, $parameters, $this->client->verifyToken(), 123, STORAGE_API_URL);
        $envs = $environment->getEnvironmentVariables();
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayNotHasKey('KBC_TOKEN', $envs);
        $this->assertArrayNotHasKey('KBC_URL', $envs);
        $this->assertArrayHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayHasKey('KBC_TOKENID', $envs);
        $this->assertArrayHasKey('KBC_TOKENDESC', $envs);
        $this->assertArrayNotHasKey('KBC_PARAMETER_MYVARIABLE', $envs);
        $this->assertArrayNotHasKey('KBC_PARAMETER_KBC_CONFIGID', $envs);
    }

    public function testExecutorVariables()
    {
        $component = [
            'forward_token' => true,
            'forward_token_details' => true,
            'inject_environment' => true,
        ];
        $parameters = [
            'myVariable' => 'fooBar',
            'KBC_CONFIGID' => 'barFoo',
        ];
        $environment = new Environment('config-test-id', $component, $parameters, $this->client->verifyToken(), 123, STORAGE_API_URL);
        $envs = $environment->getEnvironmentVariables();
        $this->assertArrayHasKey('KBC_PROJECTID', $envs);
        $this->assertArrayHasKey('KBC_CONFIGID', $envs);
        $this->assertArrayHasKey('KBC_PARAMETER_MYVARIABLE', $envs);
        $this->assertEquals($envs['KBC_PARAMETER_MYVARIABLE'], 'fooBar');
        $this->assertArrayHasKey('KBC_PARAMETER_KBC_CONFIGID', $envs);
        $this->assertEquals($envs['KBC_PARAMETER_KBC_CONFIGID'], 'barFoo');
        $this->assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        $this->assertArrayHasKey('KBC_TOKEN', $envs);
        $this->assertArrayHasKey('KBC_URL', $envs);
        $this->assertEquals($envs['KBC_TOKEN'], STORAGE_API_TOKEN);
        $this->assertArrayHasKey('KBC_PROJECTNAME', $envs);
        $this->assertArrayHasKey('KBC_TOKENID', $envs);
        $this->assertArrayHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorVariablesInvalid()
    {
        $component = [
            'forward_token' => true,
            'forward_token_details' => true,
            'inject_environment' => true,
        ];
        $parameters = [
            'myVariable' => 'fooBar',
            'KBC_CONFIGID' => 'barFoo',
            'nested' => [
                'variable' => 'not allowed'
            ]
        ];
        $environment = new Environment('config-test-id', $component, $parameters, $this->client->verifyToken(), 123, STORAGE_API_URL);
        try {
            $environment->getEnvironmentVariables();
        } catch (UserException $e) {
            $this->assertContains('Only scalar value is allowed as value', $e->getMessage());
        }
    }
}
