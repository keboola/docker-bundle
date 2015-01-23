<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration\Input\File;
use Symfony\Component\Config\Definition\Processor;

class InputFileConfigurationTest extends \PHPUnit_Framework_TestCase
{

    /**
     *
     */
    public function testConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new File();
        $config = array(
                "tags" => array("tag1", "tag2"),
                "query" => "esquery"
            );
        $expectedResponse = $config;
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedResponse, $processedConfiguration);

    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "file": At least one of 'tags' or 'query' parameters must be defined.
     */
    public function testEmptyConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new File();
        $processor->processConfiguration($configurationDefinition, array("config" => array()));
    }

}
