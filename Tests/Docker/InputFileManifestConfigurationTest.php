<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration\Input\File\Manifest;
use Symfony\Component\Config\Definition\Processor;

class InputFileManifestConfigurationTest extends \PHPUnit_Framework_TestCase
{

    /**
     *
     */
    public function testConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Manifest();
        $config = array(
            "id" => 1,
            "name" => "test",
            "created" => "2015-01-23T04:11:18+0100",
            "is_public" => false,
            "is_encrypted" => false,
            "tags" => array("tag1", "tag2"),
            "max_age_days" => 180,
            "size_bytes" => 4
        );
        $expectedResponse = $config;
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedResponse, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage The child node "id" at path "file" must be configured.
     */
    public function testEmptyConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Manifest();
        $processor->processConfiguration($configurationDefinition, array("config" => array()));
    }

}
