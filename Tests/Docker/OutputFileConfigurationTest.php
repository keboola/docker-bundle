<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration\Output\File;
use Symfony\Component\Config\Definition\Processor;

class OutputFileConfigurationTest extends \PHPUnit_Framework_TestCase
{

    public function testConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new File();
        $config = array(
                "source" => "file",
                "tags" => array("tag1", "tag2")
            );
        $expectedResponse = array(
            "source" => "file",
            "is_public" => false,
            "is_permanent" => false,
            "is_encrypted" => false,
            "notify" => false,
            "tags" => array("tag1", "tag2")
        );
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedResponse,$processedConfiguration);

    }

}
