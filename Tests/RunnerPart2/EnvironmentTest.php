<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Environment;
use PHPUnit\Framework\TestCase;

class EnvironmentTest extends TestCase
{
    private array $tokenInfo;

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
                'features' => [
                    'feature1', 'feature2', 'feature3', 'new-native-types',
                ],
            ],
            'token' => '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ];
    }

    public function testExecutorEnvs(): void
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
            'config-version-id',
            'config-row-id',
            $component,
            [],
            $this->tokenInfo,
            '123',
            (string) getenv('STORAGE_API_URL'),
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '1234',
            'connection-string',
            'debug',
            'authoritative',
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertArrayHasKey('KBC_CONFIGVERSION', $envs);
        self::assertArrayHasKey('KBC_CONFIGROWID', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertEquals('config-version-id', $envs['KBC_CONFIGVERSION']);
        self::assertEquals('config-row-id', $envs['KBC_CONFIGROWID']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST), $envs['KBC_STACKID']);
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals('keboola.test-component', $envs['KBC_COMPONENTID']);
        self::assertArrayNotHasKey('KBC_TOKEN', $envs);
        self::assertArrayNotHasKey('KBC_URL', $envs);
        self::assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayNotHasKey('KBC_TOKENID', $envs);
        self::assertArrayNotHasKey('KBC_TOKENDESC', $envs);
        self::assertEquals('1234', $envs['KBC_BRANCHID']);
        self::assertSame('aws', $envs['KBC_STAGING_FILE_PROVIDER']);
        self::assertSame('connection-string', $envs['AZURE_STORAGE_CONNECTION_STRING']);
        self::assertSame('feature1,feature2,feature3,new-native-types', $envs['KBC_PROJECT_FEATURE_GATES']);
        self::assertSame('debug', $envs['KBC_COMPONENT_RUN_MODE']);
        self::assertSame('authoritative', $envs['KBC_DATA_TYPE_SUPPORT']);
    }

    public function testExecutorForwardToken(): void
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
            'config-version-id',
            '',
            $component,
            [],
            $this->tokenInfo,
            '123',
            (string) getenv('STORAGE_API_URL'),
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            null,
            'run',
            'hint',
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertArrayHasKey('KBC_CONFIGVERSION', $envs);
        self::assertArrayNotHasKey('KBC_CONFIGROWID', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertEquals('config-version-id', $envs['KBC_CONFIGVERSION']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST), $envs['KBC_STACKID']);
        self::assertArrayHasKey('KBC_COMPONENTID', $envs);
        self::assertEquals('keboola.test-component', $envs['KBC_COMPONENTID']);
        self::assertArrayHasKey('KBC_TOKEN', $envs);
        self::assertArrayHasKey('KBC_URL', $envs);
        self::assertEquals($this->tokenInfo['token'], $envs['KBC_TOKEN']);
        self::assertArrayNotHasKey('KBC_PROJECTNAME', $envs);
        self::assertArrayNotHasKey('KBC_TOKENID', $envs);
        self::assertArrayNotHasKey('KBC_TOKENDESC', $envs);
        self::assertArrayNotHasKey('KBC_BRANCHID', $envs);
        self::assertArrayNotHasKey('KBC_REALUSER', $envs);
        self::assertSame('aws', $envs['KBC_STAGING_FILE_PROVIDER']);
        self::assertArrayNotHasKey('AZURE_STORAGE_CONNECTION_STRING', $envs);
        self::assertSame('run', $envs['KBC_COMPONENT_RUN_MODE']);
        self::assertSame('hint', $envs['KBC_DATA_TYPE_SUPPORT']);
    }

    public function testExecutorForwardTokenAndDetails(): void
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
            'config-version-id',
            null,
            $component,
            [],
            $this->tokenInfo,
            '123',
            (string) getenv('STORAGE_API_URL'),
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            null,
            'run',
            'authoritative',
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertArrayHasKey('KBC_CONFIGVERSION', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertEquals('config-version-id', $envs['KBC_CONFIGVERSION']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST), $envs['KBC_STACKID']);
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
        self::assertSame('run', $envs['KBC_COMPONENT_RUN_MODE']);
        self::assertSame('authoritative', $envs['KBC_DATA_TYPE_SUPPORT']);
    }

    public function testExecutorForwardDetails(): void
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

        $environment = new Environment(
            'config-test-id',
            'config-version-id',
            null,
            $component,
            $parameters,
            $this->tokenInfo,
            '123',
            (string) getenv('STORAGE_API_URL'),
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            'connection-string',
            'run',
            'authoritative',
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertArrayHasKey('KBC_CONFIGVERSION', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertEquals('config-version-id', $envs['KBC_CONFIGVERSION']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST), $envs['KBC_STACKID']);
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
        self::assertSame('run', $envs['KBC_COMPONENT_RUN_MODE']);
        self::assertSame('authoritative', $envs['KBC_DATA_TYPE_SUPPORT']);
    }

    public function testExecutorForwardDetailsSaml(): void
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
            'config-version-id',
            null,
            $component,
            $parameters,
            $this->tokenInfo,
            '123',
            (string) getenv('STORAGE_API_URL'),
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            null,
            'run',
            'authoritative',
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));
        self::assertArrayHasKey('KBC_PROJECTID', $envs);
        self::assertArrayHasKey('KBC_CONFIGID', $envs);
        self::assertArrayHasKey('KBC_CONFIGVERSION', $envs);
        self::assertEquals('config-test-id', $envs['KBC_CONFIGID']);
        self::assertEquals('config-version-id', $envs['KBC_CONFIGVERSION']);
        self::assertArrayHasKey('KBC_STACKID', $envs);
        self::assertEquals(parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST), $envs['KBC_STACKID']);
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
        self::assertSame('run', $envs['KBC_COMPONENT_RUN_MODE']);
        self::assertSame('authoritative', $envs['KBC_DATA_TYPE_SUPPORT']);
    }

    public function testDataTypeSupportWithoutFeature(): void
    {
        $this->tokenInfo['owner']['features'] = [
            'feature1', 'feature2', 'feature3',
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
            'config-version-id',
            null,
            $component,
            $parameters,
            $this->tokenInfo,
            '123',
            (string) getenv('STORAGE_API_URL'),
            '572-xxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            '',
            null,
            'run',
            'authoritative', // <<< from component but project doesn't support New Native Types
        );
        $envs = $environment->getEnvironmentVariables(new OutputFilter(10000));

        self::assertArrayNotHasKey('KBC_DATA_TYPE_SUPPORT', $envs);
    }
}
