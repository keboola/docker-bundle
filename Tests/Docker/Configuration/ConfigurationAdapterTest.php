<?php

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration\Container;
use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class ConfigurationAdapterTest extends TestCase
{

    protected function getStructure()
    {
        return [
            'storage' => [
                'input' => [
                    'tables' => [
                        0 => [
                            'source' => 'in.c-main.data',
                            'columns' => [
                                0 => 'Id',
                                1 => 'Name',
                            ],
                            'where_values' => [],
                            'where_operator' => 'eq',
                        ],
                    ],
                    'files' => [],
                ],
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'empty_array' => [],
                'empty_object' => new \stdClass(),
            ],
            'authorization' => [
                'oauth_api' => [
                    'id' => 1234,
                    'credentials' => [
                        'token' => 123456,
                        'params' => [
                            'key' => 'val',
                        ],
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [],
        ];
    }

    protected function getYmlConfigFileTemplate()
    {
        $data = <<< EOT
storage:
    input:
        tables:
            -
                source: in.c-main.data
                columns:
                    - Id
                    - Name
                where_values: {  }
                where_operator: eq
        files: {  }
parameters:
    primary_key_column: id
    empty_array: {  }
    empty_object: null
authorization:
    oauth_api:
        id: 1234
        credentials:
            token: 123456
            params:
                key: val
        version: 2
image_parameters: {  }

EOT;
        return $data;
    }

    protected function getJsonConfigFileTemplate()
    {
        $data = <<< EOT
{
    "storage": {
        "input": {
            "tables": [
                {
                    "source": "in.c-main.data",
                    "columns": [
                        "Id",
                        "Name"
                    ],
                    "where_values": [],
                    "where_operator": "eq"
                }
            ],
            "files": []
        }
    },
    "parameters": {
        "primary_key_column": "id",
        "empty_array": [],
        "empty_object": {}
    },
    "authorization": {
        "oauth_api": {
            "id": 1234,
            "credentials": {
                "token": 123456,
                "params": {
                    "key": "val"
                }
            },
            "version": 2
        }
    },
    "image_parameters": {}
}
EOT;
        return $data;
    }

    /**
     * @throws \Exception
     */
    public function testReadYml()
    {
        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);

        file_put_contents($root . "/config.yml", $this->getYmlConfigFileTemplate());

        $adapter = new Adapter();
        $adapter->setFormat("yaml");
        $adapter->readFromFile($root . "/config.yml");

        $str = $this->getStructure();
        $str['parameters']['empty_object'] = null;
        self::assertEquals($str, $adapter->getConfig());

        $fs->remove($root . "/config.yml");
        $fs->remove($root);
    }

    /**
     * @throws \Exception
     */
    public function testReadJson()
    {
        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);

        file_put_contents($root . "/config.json", $this->getJsonConfigFileTemplate());

        $adapter = new Adapter();
        $adapter->readFromFile($root . "/config.json");

        $str = $this->getStructure();
        $str['parameters']['empty_object'] = [];
        self::assertEquals($str, $adapter->getConfig());

        $fs->remove($root . "/config.json");
        $fs->remove($root);
    }

    public function testWriteYml()
    {
        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);

        $adapter = new Adapter();
        $adapter->setFormat("yaml");
        $adapter->setConfig($this->getStructure());
        $adapter->writeToFile($root . "/config.yml");

        self::assertEquals($this->getYmlConfigFileTemplate(), file_get_contents($root . "/config.yml"));

        $fs->remove($root . "/config.yml");
        $fs->remove($root);
    }

    public function testWriteJson()
    {
        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);

        $adapter = new Adapter();
        $adapter->setConfig($this->getStructure());
        $adapter->writeToFile($root . "/config.json");

        self::assertEquals(file_get_contents($root . "/config.json"), $this->getJsonConfigFileTemplate());

        $fs->remove($root . "/config.json");
        $fs->remove($root);
    }


    public function testConfigurationEmpty()
    {
        $temp = new Temp();
        $temp->initRunFolder();
        $container = new Container();
        $data = $container->parse(
            [
                'config' => [
                    'parameters' => [],
                    'image_parameters' => [],
                ],
            ]
        );
        self::assertEquals(
            ['parameters' => [], 'image_parameters' => []],
            $data
        );
        $adapter = new Adapter('json');
        $adapter->setConfig($data);
        $adapter->writeToFile($temp->getTmpFolder() . '/config.json');
        $string = file_get_contents($temp->getTmpFolder() . '/config.json');
        self::assertEquals(
            "{\n    \"parameters\": {},\n    \"image_parameters\": {},\n    " .
            "\"variables_id\": null,\n    \"variables_values_id\": null,\n    \"storage\": {},\n    " .
            "\"authorization\": {}\n}",
            $string
        );
    }
}
