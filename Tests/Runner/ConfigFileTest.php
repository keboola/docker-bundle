<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\OAuthV2Api\Credentials;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;

class ConfigFileTest extends \PHPUnit_Framework_TestCase
{
    public function testConfig()
    {
        $temp = new Temp();
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authorization = new Authorization($oauthClientStub, $encryptor, 'dummy-component', false);
        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $authorization, 'run', 'json');
        $config->createConfigFile(['parameters' => ['key1' => 'value1', 'key2' => ['key3' => 'value3', 'key4' => []]]]);
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
    "authorization": [],
    "action": "run"
}
SAMPLE;
        $this->assertEquals($sampleData, $data);
    }

    public function testInvalidConfig()
    {
        $temp = new Temp();
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authorization = new Authorization($oauthClientStub, $encryptor, 'dummy-component', false);

        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $authorization, 'run', 'json');
        try {
            $config->createConfigFile(['key1' => 'value1']);
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
        $encryptor = new ObjectEncryptor();
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authorization = new Authorization($oauthClientStub, $encryptor, 'dummy-component', false);
        $config = new ConfigFile($temp->getTmpFolder(), $imageConfig, $authorization, 'run', 'json');
        $config->createConfigFile($configData);
        $config = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);

        $this->assertEquals('id', $config['parameters']['primary_key_column']);
        $this->assertEquals('text', $config['parameters']['data_column']);
        $this->assertEquals('4', $config['parameters']['string_length']);
        // volatile parameters must not get stored
        $this->assertArrayNotHasKey('foo', $config['parameters']);
        $this->assertArrayNotHasKey('baz', $config['parameters']);
    }
}
