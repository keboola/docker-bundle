<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration\Output\Table;
use Symfony\Component\Config\Definition\Processor;

class OutputTableManifestConfigurationTest extends \PHPUnit_Framework_TestCase
{

    /**
     *
     */
    public function testBasicConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Table\Manifest();
        $config = array(
            "destination" => "in.c-main.test"
        );

        $expectedArray = array(
            "destination" => "in.c-main.test",
            "primary_key" => array(),
            "incremental" => false,
            "delete_where_values" => array(),
            "delete_where_operator" => "eq"
        );

        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedArray, $processedConfiguration);

    }

    /**
     *
     */
    public function testComplexConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Table\Manifest();
        $config = array(
            "destination" => "in.c-main.test",
            "incremental" => true,
            "primary_key" => array("Id", "Name"),
            "delete_where_column" => "status",
            "delete_where_values" => array("val1", "val2"),
            "delete_where_operator" => "ne"
        );

        $expectedArray = array(
            "destination" => "in.c-main.test",
            "incremental" => true,
            "primary_key" => array("Id", "Name"),
            "delete_where_column" => "status",
            "delete_where_values" => array("val1", "val2"),
            "delete_where_operator" => "ne"
        );

        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedArray, $processedConfiguration);
    }


    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "table.delete_where_operator": Invalid operator in delete_where_operator "abc"
     */
    public function testInvalidWhereOperator()
    {
        $processor = new Processor();
        $configurationDefinition = new Table\Manifest();
        $config = array(
            "destination" => "in.c-main.test",
            "delete_where_operator" => 'abc'
        );

        $processor->processConfiguration($configurationDefinition, array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage The child node "destination" at path "table" must be configured
     */
    public function testEmptyConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Table\Manifest();
        $processor->processConfiguration($configurationDefinition, array("config" => array()));
    }

}
