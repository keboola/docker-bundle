<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;

class ConfigFileTestTest extends \PHPUnit_Framework_TestCase
{

    public function setUp()
    {
        parent::setUp();
    }

    public function testConfig()
    {
        $temp = new Temp();
        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], [], 'run', 'json');
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
        $config = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], [], 'run', 'json');
        try {
            $config->createConfigFile(['key1' => 'value1']);
            $this->fail("Invalid config file must fail.");
        } catch (UserException $e) {
            $this->assertContains('Error in configuration: Unrecognized option', $e->getMessage());
        }
    }
}
