<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Image;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Filesystem;


class ImageConfigurationTest extends \PHPUnit_Framework_TestCase
{

    public function testConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Configuration\Image();
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "ondrejhlavacek/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m"
        );
        $expectedConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "ondrejhlavacek/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testEmptyConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Configuration\Image();
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => array()));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "image.definition.type": Invalid image type "whatever".
     */
    public function testWrongDefinitionType()
    {
        $processor = new Processor();
        $configurationDefinition = new Configuration\Image();
        $config = array(
            "definition" => array(
                "type" => "whatever",
                "uri" => "ondrejhlavacek/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m"
        );
        $expectedConfiguration  = $config;
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "image.configuration_format": Invalid configuration_format "fail".
     */
    public function testWrongConfigurationFormat()
    {
        $processor = new Processor();
        $configurationDefinition = new Configuration\Image();
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "ondrejhlavacek/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "fail"
        );
        $expectedConfiguration  = $config;
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

}
