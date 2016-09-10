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
            "vendor" => array("a" => "b"),
            "image_parameters" => array("foo" => "bar"),
            "synchronous_actions" => ["test", "test2"],
            "network" => "none",
            "logging" => [
                "type" => "gelf",
                "verbosity" => [200 => "verbose"]
            ]
        );
        $expectedConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo",
                "tag" => "latest"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "process_timeout" => 3600,
            "forward_token" => false,
            "forward_token_details" => false,
            "default_bucket" => false,
            "vendor" => array("a" => "b"),
            "image_parameters" => array("foo" => "bar"),
            "synchronous_actions" => ["test", "test2"],
            "network" => "none",
            "logging" => [
                "type" => "gelf",
                "verbosity" => [200 => "verbose"],
                "gelf_server_type" => "tcp",
            ]
        );
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    public function testEmptyConfiguration()
    {
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            )
        );
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $expectedConfiguration = [
            'definition' => [
                'type' => 'dockerhub',
                'uri' => 'keboola/docker-demo',
                'tag' => 'latest',
            ],
            'cpu_shares' => 1024,
            'memory' => '64m',
            'configuration_format' => 'json',
            'process_timeout' => 3600,
            'forward_token' => false,
            'forward_token_details' => false,
            'default_bucket' => false,
            'synchronous_actions' => [],
        ];
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testTotallyEmptyConfiguration()
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
        (new Configuration\Image())->parse(array("config" => $config));
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
        (new Configuration\Image())->parse(array("config" => $config));
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
        (new Configuration\Image())->parse(array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "image.network": Invalid network type "whatever".
     */
    public function testWrongNetwokType()
    {
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "network" => "whatever"
        );
        (new Configuration\Image())->parse(array("config" => $config));
    }

    public function testBuilderConfiguration()
    {
        $config = [
            "definition" => [
                "type" => "builder",
                "uri" => "keboola/docker-base-r",
                "tag" => "somebranch",
                "build_options" => [
                    "repository" => [
                        "type" => "git",
                        "uri" => "https://bitbucket.org/xpopelkaTest/test-r-transformation.git",
                        "username" => "foo",
                        "#password" => "KBC::Encrypted==abc==",
                    ],
                    "commands" => [
                        "git clone {{repository}} /home/"
                    ],
                    "entry_point" => "Rscript /home/script.R",
                    "parameters" => [],
                    "cache" => true
                ]
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "process_timeout" => 3600,
            "forward_token" => false,
            "forward_token_details" => false,
            "default_bucket" => true,
            "default_bucket_stage" => "out",
            "synchronous_actions" => [],
        ];

        $expectedConfiguration = $config;
        $processedConfiguration = (new Configuration\Image())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }
}
