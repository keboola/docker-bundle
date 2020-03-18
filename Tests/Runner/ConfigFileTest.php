<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\OAuthV2Api\Credentials;
use Keboola\Temp\Temp;

class ConfigFileTest extends BaseRunnerTest
{
    private function getAuthorization()
    {
        $oauthClientStub = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        return new Authorization($oauthClientStub, $oauthClientStub, $this->getEncryptorFactory()->getEncryptor(), 'dummy-component');
    }

    public function testConfig()
    {
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile(
            ['parameters' => ['key1' => 'value1', 'key2' => ['key3' => 'value3', 'key4' => []]]],
            new OutputFilter(),
            []
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
    "storage": {},
    "authorization": {}
}
SAMPLE;
        self::assertEquals($sampleData, $data);
    }

    public function testInvalidConfig()
    {
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $this->getAuthorization(), 'run', 'json');
        self::expectException(UserException::class);
        self::expectExceptionMessage('Error in configuration: Unrecognized option');
        $config->createConfigFile(['key1' => 'value1'], new OutputFilter(), []);
    }

    public function testDefinitionParameters()
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
            ]
        ];

        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $imageConfig, $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile($configData, new OutputFilter(), []);
        $config = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);

        self::assertEquals('id', $config['parameters']['primary_key_column']);
        self::assertEquals('text', $config['parameters']['data_column']);
        self::assertEquals('4', $config['parameters']['string_length']);
        // volatile parameters must not get stored
        self::assertArrayNotHasKey('foo', $config['parameters']);
        self::assertArrayNotHasKey('baz', $config['parameters']);
    }

    public function testEmptyConfig()
    {
        $imageConfig = [];
        $configData = [];
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $imageConfig, $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile($configData, new OutputFilter(), []);
        $config = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);

        self::assertArrayHasKey('storage', $config);
        self::assertArrayHasKey('authorization', $config);
        self::assertArrayHasKey('parameters', $config);
        self::assertArrayHasKey('image_parameters', $config);
        self::assertArrayHasKey('action', $config);
        self::assertEquals('run', $config['action']);
    }

    public function testWorkspaceConfig()
    {
        $imageConfig = [];
        $configData = [];
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), $imageConfig, $this->getAuthorization(), 'run', 'json');
        $config->createConfigFile($configData, new OutputFilter(), ['host' => 'foo', 'user' => 'bar']);
        $config = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);

        self::assertArrayHasKey('storage', $config);
        self::assertArrayHasKey('authorization', $config);
        self::assertArrayHasKey('parameters', $config);
        self::assertArrayHasKey('image_parameters', $config);
        self::assertArrayHasKey('action', $config);
        self::assertEquals('run', $config['action']);
        self::assertEquals(['host' => 'foo', 'user' => 'bar'], $config['authorization']['workspace']);
    }
}
