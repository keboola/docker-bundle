<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Environment;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    /**
     * @var array
     */
    private $tokenInfo;

    public function setUp()
    {
        parent::setUp();
        $this->tokenInfo = [
            'description' => 'dummy token',
            'id' => '123',
            'owner' => [
                'id' => '321',
                'name' => 'some person',
            ],
            'token' => '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
        ];
    }

    public function testExecutorEnvs()
    {
        $component = new Component([
            'id' => 'keboola.test-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
                'forward_token' => false,
                'forward_token_details' => false,
            ],
        ]);
        $environment = new Environment(
            'config-test-id',
            'config-row-id',
            $component,
            [],
            $this->tokenInfo,
            123,
            STORAGE_API_URL,
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter());
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertArrayHasKey('KBC_CONFIGROWID', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertEquals('config-row-id', $envs['KBC_CONFIGROWID']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals($envs['KBC_STACKID'], parse_url(STORAGE_API_URL, PHP_URL_HOST));
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals($envs['KBC_COMPONENTID'], 'keboola.test-component');
        self::assertArrayNotHasKey('KBC_TOKEN', $envs);
        self::assertArrayNotHasKey('KBC_URL', $envs);
        self::assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayNotHasKey('KBC_TOKENID', $envs);
        self::assertArrayNotHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardToken()
    {
        $component = new Component([
            'id' => 'keboola.test-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
                'forward_token' => true,
                'forward_token_details' => false,
            ],
        ]);
        $environment = new Environment(
            'config-test-id',
            '',
            $component,
            [],
            $this->tokenInfo,
            123,
            STORAGE_API_URL,
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter());
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertArrayNotHasKey('KBC_CONFIGROWID', $envs);
        self::assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals($envs['KBC_STACKID'], parse_url(STORAGE_API_URL, PHP_URL_HOST));
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals($envs['KBC_COMPONENTID'], 'keboola.test-component');
        self::assertArrayHasKey('KBC_TOKEN', $envs);
        self::assertArrayHasKey('KBC_URL', $envs);
        self::assertEquals($envs['KBC_TOKEN'], $this->tokenInfo['token']);
        self::assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayNotHasKey('KBC_TOKENID', $envs);
        self::assertArrayNotHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardTokenAndDetails()
    {
        $component = new Component([
            'id' => 'keboola.test-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
                'forward_token' => true,
                'forward_token_details' => true,
            ],
        ]);
        $environment = new Environment('config-test-id', null, $component, [], $this->tokenInfo, 123, STORAGE_API_URL, '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
        $envs = $environment->getEnvironmentVariables(new OutputFilter());
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals($envs['KBC_STACKID'], parse_url(STORAGE_API_URL, PHP_URL_HOST));
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals($envs['KBC_COMPONENTID'], 'keboola.test-component');
        self::assertArrayHasKey('KBC_TOKEN', $envs);
        self::assertArrayHasKey('KBC_URL', $envs);
        self::assertEquals($envs['KBC_TOKEN'], $this->tokenInfo['token']);
        self::assertArrayHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayHasKey('KBC_TOKENID', $envs);
        self::assertArrayHasKey('KBC_TOKENDESC', $envs);
    }

    public function testExecutorForwardDetails()
    {
        $component = new Component([
            'id' => 'keboola.test-component',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
                'forward_token' => false,
                'forward_token_details' => true,
            ],
        ]);
        $parameters = [
            'myVariable' => 'fooBar',
            'KBC_CONFIGID' => 'barFoo',
        ];
        $environment = new Environment('config-test-id', null, $component, $parameters, $this->tokenInfo, 123, STORAGE_API_URL, '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
        $envs = $environment->getEnvironmentVariables(new OutputFilter());
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertEquals($envs['KBC_CONFIGID'], 'config-test-id');
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals($envs['KBC_STACKID'], parse_url(STORAGE_API_URL, PHP_URL_HOST));
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals($envs['KBC_COMPONENTID'], 'keboola.test-component');
        self::assertArrayNotHasKey('KBC_TOKEN', $envs);
        self::assertArrayNotHasKey('KBC_URL', $envs);
        self::assertArrayHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayHasKey('KBC_TOKENID', $envs);
        self::assertArrayHasKey('KBC_TOKENDESC', $envs);
    }
}
