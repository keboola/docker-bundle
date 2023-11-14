<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Generator;
use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\Temp\Temp;

class ConfigFileTest extends BaseRunnerTest
{
    private function getAuthorization(): Authorization
    {
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();

        $jobScopedEncryptor = new JobScopedEncryptor(
            $this->getEncryptor(),
            'keboola.docker-demo-sync',
            '12345',
            null,
            ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            [],
        );

        /** @var Credentials $oauthClientStub */
        return new Authorization($oauthClientStub, $jobScopedEncryptor, 'dummy-component');
    }

    public function testConfig(): void
    {
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile(
            ['parameters' => ['key1' => 'value1', 'key2' => ['key3' => 'value3', 'key4' => []]]],
            new OutputFilter(10000),
            [],
            ['fooBar' => 'baz'],
        );
        $data = file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json');
        $sampleData = <<<SAMPLE
{
    "parameters": {
        "key1": "value1",
        "key2": {
            "key3": "value3",
            "key4": []
        }
    },
    "image_parameters": {
        "fooBar": "baz"
    },
    "action": "run",
    "shared_code_row_ids": [],
    "storage": {},
    "authorization": {}
}
SAMPLE;
        self::assertEquals($sampleData, $data);
    }

    public function testInvalidConfig(): void
    {
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $this->getAuthorization(), 'run', 'json');
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Error in configuration: Unrecognized option');
        $config->createConfigFile(['key1' => 'value1'], new OutputFilter(10000), [], ['fooBar' => 'baz']);
    }

    public function definitionParametersData(): Generator
    {
        yield 'no context' => [null];
        yield 'backend context' => ['wlm'];
    }

    /**
     * @dataProvider definitionParametersData
     */
    public function testDefinitionParameters(?string $expectedAuthContext): void
    {
        $imageConfig = [
            'definition' => [
                'type' => 'dockerhub',
                'uri' => 'keboola/docker-demo',
            ],
            'configuration_format' => 'yaml',
        ];

        $configData = [
            'storage' => [
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => '4',
            ],
            'runtime' => [
                'foo' => 'bar',
                'baz' => 'next',
            ],
        ];

        if ($expectedAuthContext) {
            $configData['runtime']['backend']['context'] = $expectedAuthContext;
        }

        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile($configData, new OutputFilter(10000), [], $imageConfig);
        $config = json_decode(
            (string) file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'),
            true,
        );

        self::assertIsArray($config);
        self::assertEquals('id', $config['parameters']['primary_key_column']);
        self::assertEquals('text', $config['parameters']['data_column']);
        self::assertEquals('4', $config['parameters']['string_length']);
        // volatile parameters must not get stored
        self::assertArrayNotHasKey('foo', $config['parameters']);
        self::assertArrayNotHasKey('baz', $config['parameters']);

//        self::assertArrayHasKey('authorization', $config);
        if ($expectedAuthContext) {
            self::assertArrayHasKey('context', $config['authorization']);
            self::assertSame($expectedAuthContext, $config['authorization']['context']);
        } else {
            self::assertArrayNotHasKey('context', $config['authorization']);
        }
    }

    public function testEmptyConfig(): void
    {
        $imageConfig = [];
        $configData = [];
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile($configData, new OutputFilter(10000), [], $imageConfig);
        $config = json_decode(
            (string) file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'),
            true,
        );

        self::assertIsArray($config);
        self::assertArrayHasKey('storage', $config);
        self::assertArrayHasKey('authorization', $config);
        self::assertArrayHasKey('parameters', $config);
        self::assertArrayHasKey('image_parameters', $config);
        self::assertArrayHasKey('action', $config);
        self::assertEquals('run', $config['action']);
        self::assertArrayNotHasKey('context', $config['authorization']);
    }

    public function workspaceConfigData(): Generator
    {
        yield 'empty config data' => [
            [],
            null,
        ];
        yield 'backend context' => [
            [
                'runtime' => [
                    'backend' => [
                        'context' => 'wlm',
                    ],
                ],
            ],
            'wlm',
        ];
    }

    /**
     * @dataProvider workspaceConfigData
     */
    public function testWorkspaceConfig(array $configData, ?string $expectedAuthContext): void
    {
        $imageConfig = [];
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile(
            $configData,
            new OutputFilter(10000),
            ['host' => 'foo', 'user' => 'bar'],
            $imageConfig,
        );
        $config = json_decode(
            (string) file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'),
            true,
        );

        self::assertIsArray($config);
        self::assertArrayHasKey('storage', $config);
        self::assertArrayHasKey('authorization', $config);
        self::assertArrayHasKey('parameters', $config);
        self::assertArrayHasKey('image_parameters', $config);
        self::assertArrayHasKey('action', $config);
        self::assertSame('run', $config['action']);
        self::assertSame(['host' => 'foo', 'user' => 'bar'], $config['authorization']['workspace']);

        if ($expectedAuthContext) {
            self::assertArrayHasKey('context', $config['authorization']);
            self::assertSame($expectedAuthContext, $config['authorization']['context']);
        } else {
            self::assertArrayNotHasKey('context', $config['authorization']);
        }
    }

    /**
     * @dataProvider imageParametersMisuseData
     */
    public function testImageParametersMisuse(
        array $imageParameters,
        array $configData,
        string $exceptionPath,
    ): void {
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $this->getAuthorization(), 'run', 'json');

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(sprintf(
            'Component secrets cannot be used in configurations (used in "%s"). ' .
            'Please contact support if you need further explanation.',
            $exceptionPath
        ));

        $config->createConfigFile(
            $configData,
            new OutputFilter(10000),
            ['host' => 'foo', 'user' => 'bar'],
            $imageParameters,
        );
    }

    public function imageParametersMisuseData(): iterable
    {
        yield 'same key' => [
            'imageParameters' => [
                '#secret' => 'foobar5678',
            ],
            'configData' => [
                'parameters' => [
                    '#secret' => 'foobar5678',
                ],
            ],
            'exceptionPath' => 'parameters.#secret',
        ];

        yield 'different key' => [
            'imageParameters' => [
                '#secret' => 'foobar5678',
            ],
            'configData' => [
                'parameters' => [
                    '#secret' => 'foobar5678',
                ],
            ],
            'exceptionPath' => 'parameters.#secret',
        ];

        yield 'different depth' => [
            'imageParameters' => [
                '#secret' => 'foobar5678',
            ],
            'configData' => [
                'parameters' => [
                    'credentials' => [
                        '#other-secret' => 'foobar5678',
                    ],
                ],
            ],
            'exceptionPath' => 'parameters.credentials.#other-secret',
        ];
    }

    /**
     * @dataProvider imageParametersNoMisuseData
     */
    public function testImageParametersNoMisuse(
        array $imageParameters,
        array $configData,
    ): void {
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $this->getAuthorization(), 'run', 'json');

        $config->createConfigFile(
            $configData,
            new OutputFilter(10000),
            ['host' => 'foo', 'user' => 'bar'],
            $imageParameters,
        );

        $config = json_decode(
            (string) file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'),
            true,
        );

        self::assertIsArray($config);
    }

    public function imageParametersNoMisuseData(): iterable
    {
        yield 'not a secret in image parameters' => [
            'imageParameters' => [
                'secret' => 'foobar5678',
            ],
            'configData' => [
                'parameters' => [
                    '#secret' => 'foobar5678',
                ],
            ],
            'exceptionPath' => 'parameters.#secret',
        ];

        yield 'not a secret in config' => [
            'imageParameters' => [
                '#secret' => 'foobar5678',
            ],
            'configData' => [
                'parameters' => [
                    'secret' => 'foobar5678',
                ],
            ],
            'exceptionPath' => 'parameters.#secret',
        ];

        yield 'different secret value' => [
            'imageParameters' => [
                '#secret' => '12345678',
            ],
            'configData' => [
                'parameters' => [
                    '#secret' => 'foobar5678',
                ],
            ],
            'exceptionPath' => 'parameters.#secret',
        ];
    }
}
