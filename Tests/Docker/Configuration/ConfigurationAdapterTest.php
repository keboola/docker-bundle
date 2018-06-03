<?php

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration\Container\Adapter;
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
    }
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

    /**
     *
     */
    public function testWriteYml()
    {
        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);

        $adapter = new Adapter();
        $adapter->setFormat("yaml");
        $adapter->setConfig($this->getStructure());
        $adapter->writeToFile($root . "/config.yml");

        self::assertEquals(file_get_contents($root . "/config.yml"), $this->getYmlConfigFileTemplate());

        $fs->remove($root . "/config.yml");
        $fs->remove($root);
    }

    /**
     *
     */
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
}
