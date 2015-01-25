<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Configuration\Adapter;
use Keboola\DockerBundle\Docker\Image;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Filesystem\Filesystem;


class MockConfiguration extends Configuration
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $treeBuilder->root("test")
            ->children()
            ->variableNode("user")->end()
            ->variableNode("storage")->end();
        return $treeBuilder;
    }
}

class MockAdapter extends Adapter
{
    protected $configClass = 'Keboola\DockerBundle\Tests\MockConfiguration';
}

class ConfigurationAdapterTest extends \PHPUnit_Framework_TestCase
{

    protected $structure = array(
        'storage' =>
            array(
                'input' =>
                    array(
                        'tables' =>
                            array(
                                0 =>
                                    array(
                                        'source' => 'in.c-main.data',
                                        'columns' =>
                                            array(
                                                0 => 'Id',
                                                1 => 'Name',
                                            ),
                                    ),
                            ),
                    ),
            ),
        'user' =>
            array(
                'primary_key_column' => 'id',
            ),
    );

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
user:
    primary_key_column: id

EOT;
        return $data;
    }

    protected function getJsonConfigFileTemplate()
    {
        $data = <<< EOT
{"storage":{"input":{"tables":[{"source":"in.c-main.data","columns":["Id","Name"]}]}},"user":{"primary_key_column":"id"}}
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

        $adapter = new MockAdapter();
        $adapter->readFromFile($root . "/config.yml");

        $this->assertEquals($this->structure, $adapter->getConfig());

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

        $adapter = new MockAdapter();
        $adapter->setFormat("json");
        $adapter->readFromFile($root . "/config.json");

        $this->assertEquals($this->structure, $adapter->getConfig());

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

        $adapter = new MockAdapter();
        $adapter->setConfig($this->structure);
        $adapter->writeToFile($root . "/config.yml");

        $this->assertEquals(file_get_contents($root . "/config.yml"), $this->getYmlConfigFileTemplate());

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

        $adapter = new MockAdapter();
        $adapter->setConfig($this->structure);
        $adapter->setFormat("json");
        $adapter->writeToFile($root . "/config.json");

        $this->assertEquals(file_get_contents($root . "/config.json"), $this->getJsonConfigFileTemplate());

        $fs->remove($root . "/config.json");
        $fs->remove($root);

    }
}
