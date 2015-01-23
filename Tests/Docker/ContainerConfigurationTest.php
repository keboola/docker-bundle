<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Image;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Filesystem;


class ContainerConfigurationTest extends \PHPUnit_Framework_TestCase
{

    public function testConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Configuration\Container();
        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array(
            "config" => array(
                "system" => array(
                    "image_tag" => "0.6"
                ),
                "storage" => array(
                    "input" => array(
                        "tables" => array(
                            array(
                                "source" => "in.c-main.data"
                            )
                        ),
                        "files" => array(
                            array(
                                "tags" => array("tag1", "tag2"),
                                "query" => "esquery"
                            )
                        )

                    )
                ),
                "user" => array(
                    array("var1" => "val1"),
                    array("arr1" => array("var2" => "val2"))
                )
            )
        ));
    }

}
