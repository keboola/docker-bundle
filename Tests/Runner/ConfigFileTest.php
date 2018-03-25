<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;

class ConfigFileTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        $this->encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
    }

    public function testConfig()
    {
        $temp = new Temp();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $authorization = new Authorization($oauthClientStub, $oauthClientStub, $this->encryptorFactory->getEncryptor(), 'dummy-component', false);
        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $authorization, 'run', 'json');
        $config->createConfigFile(
            ['parameters' => ['key1' => 'value1', 'key2' => ['key3' => 'value3', 'key4' => []]]],
            new OutputFilter()
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
    "action": "run"
}
SAMPLE;
        $this->assertEquals($sampleData, $data);
    }

    public function testInvalidConfig()
    {
        $temp = new Temp();

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $authorization = new Authorization($oauthClientStub, $oauthClientStub, $this->encryptorFactory->getEncryptor(), 'dummy-component', false);

        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $authorization, 'run', 'json');
        try {
            $config->createConfigFile(['key1' => 'value1'], new OutputFilter());
            $this->fail("Invalid config file must fail.");
        } catch (UserException $e) {
            $this->assertContains('Error in configuration: Unrecognized option', $e->getMessage());
        }
    }

    public function testDefinitionParameters()
    {
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "configuration_format" => "yaml",
        ];

        $configData = [
            "storage" => [
            ],
            "parameters" => [
                "primary_key_column" => "id",
                "data_column" => "text",
                "string_length" => "4"
            ],
            "runtime" => [
                "foo" => "bar",
                "baz" => "next"
            ]
        ];

        $temp = new Temp();

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $authorization = new Authorization($oauthClientStub, $oauthClientStub, $this->encryptorFactory->getEncryptor(), 'dummy-component', false);
        $config = new ConfigFile($temp->getTmpFolder(), $imageConfig, $authorization, 'run', 'json');
        $config->createConfigFile($configData, new OutputFilter());
        $config = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);

        $this->assertEquals('id', $config['parameters']['primary_key_column']);
        $this->assertEquals('text', $config['parameters']['data_column']);
        $this->assertEquals('4', $config['parameters']['string_length']);
        // volatile parameters must not get stored
        $this->assertArrayNotHasKey('foo', $config['parameters']);
        $this->assertArrayNotHasKey('baz', $config['parameters']);
    }

    public function testEmptyConfig()
    {
        $imageConfig = [];
        $configData = [];

        $temp = new Temp();

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $authorization = new Authorization($oauthClientStub, $oauthClientStub, $this->encryptorFactory->getEncryptor(), 'dummy-component', false);
        $config = new ConfigFile($temp->getTmpFolder(), $imageConfig, $authorization, 'run', 'json');
        $config->createConfigFile($configData, new OutputFilter());
        $config = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);

        $this->assertArrayNotHasKey('storage', $config);
        $this->assertArrayNotHasKey('authorization', $config);
        $this->assertArrayNotHasKey('parameters', $config);
        $this->assertArrayHasKey('image_parameters', $config);
        $this->assertArrayHasKey('action', $config);
        $this->assertEquals('run', $config['action']);
    }
}
