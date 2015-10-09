<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Image;

class ImageConfigurationTest extends \PHPUnit_Framework_TestCase
{

    public function testConfiguration()
    {
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "vendor" => array("a" => "b")
        );
        $expectedConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml",
            "process_timeout" => 3600,
            "forward_token" => false,
            "forward_token_details" => false,
            "streaming_logs" => true,
            "vendor" => array("a" => "b")
        );
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testEmptyConfiguration()
    {
        (new Configuration\Image())->parse(array("config" => array()));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "image.definition.type": Invalid image type "whatever".
     */
    public function testWrongDefinitionType()
    {
        $config = array(
            "definition" => array(
                "type" => "whatever",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m"
        );
        $expectedConfiguration = $config;
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "image.configuration_format": Invalid configuration_format "fail".
     */
    public function testWrongConfigurationFormat()
    {
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "fail"
        );
        $expectedConfiguration = $config;
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Unrecognized option "unknown" under "image"
     */
    public function testExtraConfigurationField()
    {
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "unknown" => array()
        );
        $expectedConfiguration = $config;
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }


    public function testBuilderConfiguration()
    {
        $config = [
            "definition" => [
                "type" => "builder",
                "uri" => "https://github.com/odinuv/tm-docker.git",
                "build_options" => [
                    "credentials" => [
                        "user_name" => "foo",
                        "#password" => "bar"
                    ],
                    "entry_point" => "script.R"
                ]
            ]
        ];
        $expectedConfiguration = $config;
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }


    public function testDecrypt()
    {
        $config = array(
            "definition" => array(
                "type" => "dockerhub-private",
                "uri" => "keboolaprivatetest/docker-demo-docker",
                "repository" => array(
                    "email" => DOCKERHUB_PRIVATE_EMAIL,
                    "#password" => DOCKERHUB_PRIVATE_PASSWORD,
                    "username" => DOCKERHUB_PRIVATE_USERNAME,
                    "server" => DOCKERHUB_PRIVATE_SERVER
                )
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "yaml"
        );

        $expectedConfiguration = $config;
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertNotContains(
            'Keboola::encrypted',
            $processedConfiguration['definition']['repository']['#password']
        );
        unset($processedConfiguration['definition']['repository']['#password']);
        unset($config['definition']['repository']['#password']);
        $this->assertEquals($expectedConfiguration, $processedConfiguration);

    }
}
