<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration\Input\Table;
use Symfony\Component\Config\Definition\Processor;

class InputTableConfigurationTest extends \PHPUnit_Framework_TestCase
{

    /**
     *
     */
    public function testBasicConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Table();
        $config = array(
            "source" => "in.c-main.test"
        );

        $expectedArray = array(
            "source" => "in.c-main.test",
            "columns" => array(),
            "where_values" => array(),
            "where_operator" => "eq"
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
        $configurationDefinition = new Table();
        $config = array(
            "source" => "in.c-main.test",
            "destination" => "test",
            "days" => 1,
            "columns" => array("Id", "Name"),
            "where_column" => "status",
            "where_values" => array("val1", "val2"),
            "where_operator" => "ne"
        );

        $expectedArray = array(
            "source" => "in.c-main.test",
            "destination" => "test",
            "days" => 1,
            "columns" => array("Id", "Name"),
            "where_column" => "status",
            "where_values" => array("val1", "val2"),
            "where_operator" => "ne"
        );

        $processedConfiguration = $processor->processConfiguration($configurationDefinition, array("config" => $config));
        $this->assertEquals($expectedArray, $processedConfiguration);
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage The value -1 is too small for path "table.days". Should be greater than or equal to 0
     */
    public function testInvalidDays()
    {
        $processor = new Processor();
        $configurationDefinition = new Table();
        $config = array(
            "source" => "in.c-main.test",
            "days" => -1
        );

        $processor->processConfiguration($configurationDefinition, array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "table.where_operator": Invalid operator in where_operator "abc"
     */
    public function testInvalidWhereOperator()
    {
        $processor = new Processor();
        $configurationDefinition = new Table();
        $config = array(
            "source" => "in.c-main.test",
            "where_operator" => 'abc'
        );

        $processor->processConfiguration($configurationDefinition, array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage The child node "source" at path "table" must be configured
     */
    public function testEmptyConfiguration()
    {
        $processor = new Processor();
        $configurationDefinition = new Table();
        $processor->processConfiguration($configurationDefinition, array("config" => array()));
    }

}
