<?php

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Environment;
use Keboola\DockerBundle\Docker\Runner\MlflowTracking;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    /**
     * @var array
     */
    private $tokenInfo;

    public function setUp(): void
    {
        parent::setUp();
        $this->tokenInfo = [
            'description' => 'dummy token',
            'id' => '123',
            'owner' => [
                'id' => '321',
                'name' => 'some project',
                'fileStorageProvider' => 'aws',
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

        $mlflowTracking = new MlflowTracking('mlflow-uri', 'mlflow-token');
        $environment = new Environment(
            'config-test-id',
            'config-row-id',
            $component,
            [],
            $this->tokenInfo,
            123,
            STORAGE_API_URL,
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '1234',
            'connection-string',
            $mlflowTracking
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
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
        self::assertEquals('1234', $envs['KBC_BRANCHID']);
        self::assertSame('aws', $envs['KBC_STAGING_FILE_PROVIDER']);
        self::assertSame('connection-string', $envs['AZURE_STORAGE_CONNECTION_STRING']);
        self::assertSame('mlflow-uri', $envs['MLFLOW_TRACKING_URI']);
        self::assertSame('mlflow-token', $envs['MLFLOW_TRACKING_TOKEN']);
    }

    public function testExecutorForwardToken()
    {
        $this->tokenInfo['admin']['samlParameters'] = [
            'userId' => 'boo',
            'someOtherValue' => 'foo',
        ];
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
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            null,
            null
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
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
        self::assertArrayNotHasKey('KBC_BRANCHID', $envs);
        self::assertArrayNotHasKey('KBC_REALUSER', $envs);
        self::assertSame('aws', $envs['KBC_STAGING_FILE_PROVIDER']);
        self::assertArrayNotHasKey('AZURE_STORAGE_CONNECTION_STRING', $envs);
        self::assertArrayNotHasKey('MLFLOW_TRACKING_URI', $envs);
        self::assertArrayNotHasKey('MLFLOW_TRACKING_TOKEN', $envs);
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
        $environment = new Environment(
            'config-test-id',
            null,
            $component,
            [],
            $this->tokenInfo,
            123,
            STORAGE_API_URL,
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            null,
            null
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url(STORAGE_API_URL, PHP_URL_HOST), $envs['KBC_STACKID']);
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals('keboola.test-component', $envs['KBC_COMPONENTID']);
        self::assertArrayHasKey('KBC_TOKEN', $envs);
        self::assertArrayHasKey('KBC_URL', $envs);
        self::assertEquals($this->tokenInfo['token'], $envs['KBC_TOKEN']);
        self::assertArrayHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayHasKey('KBC_TOKENID', $envs);
        self::assertArrayHasKey('KBC_TOKENDESC', $envs);
        self::assertArrayNotHasKey('KBC_BRANCHID', $envs);
        self::assertSame('aws', $envs['KBC_STAGING_FILE_PROVIDER']);
        self::assertArrayNotHasKey('AZURE_STORAGE_CONNECTION_STRING', $envs);
        self::assertArrayNotHasKey('MLFLOW_TRACKING_URI', $envs);
        self::assertArrayNotHasKey('MLFLOW_TRACKING_TOKEN', $envs);
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

        $mlflowTracking = new MlflowTracking('mlflow-uri', 'mlflow-token');
        $environment = new Environment(
            'config-test-id',
            null,
            $component,
            $parameters,
            $this->tokenInfo,
            123,
            STORAGE_API_URL,
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            'connection-string',
            $mlflowTracking
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url(STORAGE_API_URL, PHP_URL_HOST), $envs['KBC_STACKID']);
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals('keboola.test-component', $envs['KBC_COMPONENTID']);
        self::assertArrayNotHasKey('KBC_TOKEN', $envs);
        self::assertArrayNotHasKey('KBC_URL', $envs);
        self::assertArrayHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayHasKey('KBC_TOKENID', $envs);
        self::assertArrayHasKey('KBC_TOKENDESC', $envs);
        self::assertArrayNotHasKey('KBC_BRANCHID', $envs);
        self::assertArrayNotHasKey('KBC_REALUSER', $envs);
        self::assertSame('aws', $envs['KBC_STAGING_FILE_PROVIDER']);
        self::assertSame('mlflow-uri', $envs['MLFLOW_TRACKING_URI']);
        self::assertSame('mlflow-token', $envs['MLFLOW_TRACKING_TOKEN']);
    }

    public function testExecutorForwardDetailsSaml()
    {
        $this->tokenInfo['admin']['samlParameters'] = [
            'userId' => 'boo',
            'someOtherValue' => 'foo',
        ];
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
        $environment = new Environment(
            'config-test-id',
            null,
            $component,
            $parameters,
            $this->tokenInfo,
            123,
            STORAGE_API_URL,
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            null,
            null
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url(STORAGE_API_URL, PHP_URL_HOST), $envs['KBC_STACKID']);
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals('keboola.test-component', $envs['KBC_COMPONENTID']);
        self::assertArrayNotHasKey('KBC_TOKEN', $envs);
        self::assertArrayNotHasKey('KBC_URL', $envs);
        self::assertArrayHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayHasKey('KBC_TOKENID', $envs);
        self::assertArrayHasKey('KBC_TOKENDESC', $envs);
        self::assertArrayNotHasKey('KBC_BRANCHID', $envs);
        self::assertEquals('boo', $envs['KBC_REALUSER']);
        self::assertSame('aws', $envs['KBC_STAGING_FILE_PROVIDER']);
        self::assertArrayNotHasKey('AZURE_STORAGE_CONNECTION_STRING', $envs);
        self::assertArrayNotHasKey('MLFLOW_TRACKING_URI', $envs);
        self::assertArrayNotHasKey('MLFLOW_TRACKING_TOKEN', $envs);
    }
}
