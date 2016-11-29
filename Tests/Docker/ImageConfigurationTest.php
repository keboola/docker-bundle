<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;

class ImageConfigurationTest extends \PHPUnit_Framework_TestCase
{

    public function testConfiguration()
    {
        $config = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "vendor" => ["a" => "b"],
            "image_parameters" => ["foo" => "bar"],
            "synchronous_actions" => ["test", "test2"],
            "network" => "none",
            "logging" => [
                "type" => "gelf",
                "verbosity" => [200 => "verbose"]
            ]
        ];
        $expectedConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo",
                "tag" => "latest"
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
            "process_timeout" => 3600,
            "forward_token" => false,
            "forward_token_details" => false,
            "default_bucket" => false,
            "default_bucket_stage" => "in",
            "vendor" => ["a" => "b"],
            "image_parameters" => ["foo" => "bar"],
            "synchronous_actions" => ["test", "test2"],
            "network" => "none",
            "logging" => [
                "type" => "gelf",
                "verbosity" => [200 => "verbose"],
                "gelf_server_type" => "tcp",
            ],
            "staging_storage" => [
                "input" => "local"
            ]
        ];
        $processedConfiguration = (new Configuration\Component())->parse(["config" => $config]);
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    public function testEmptyConfiguration()
    {
        $config = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ]
        ];
        $processedConfiguration = (new Configuration\Component())->parse(["config" => $config]);
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
            'default_bucket_stage' => 'in',
            'staging_storage' => [
                'input' => 'local'
            ]
        ];
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "component.definition.type": Invalid image type "whatever".
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
        (new Configuration\Component())->parse(array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "component.configuration_format": Invalid configuration_format "fail".
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
        (new Configuration\Component())->parse(array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Unrecognized option "unknown" under "component"
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
        (new Configuration\Component())->parse(array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "component.network": Invalid network type "whatever".
     */
    public function testWrongNetworkType()
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
        (new Configuration\Component())->parse(array("config" => $config));
    }

    public function testWrongStagingStorageType()
    {
        $this->expectException('\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException');
        $this->expectExceptionMessage(
            'The value "whatever" is not allowed for path "component.staging_storage.input". Permissible values: "local", "s3"'
        );
        $config = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            ),
            "cpu_shares" => 1024,
            "memory" => "64m",
            "staging_storage" => [
                "input" => "whatever"
            ]
        );
        (new Configuration\Component())->parse(array("config" => $config));
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
            "staging_storage" => [
                "input" => "local"
            ]
        ];

        $expectedConfiguration = $config;
        $processedConfiguration = (new Configuration\Component())->parse(array("config" => $config));
        $this->assertEquals($expectedConfiguration, $processedConfiguration);
    }
}
