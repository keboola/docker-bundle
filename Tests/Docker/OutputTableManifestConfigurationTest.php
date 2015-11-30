<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Configuration\Output\Table;

class OutputTableManifestConfigurationTest extends \PHPUnit_Framework_TestCase
{

    /**
     *
     */
    public function testBasicConfiguration()
    {
        $config = array(
            "destination" => "in.c-main.test"
        );

        $expectedArray = array(
            "destination" => "in.c-main.test",
            "primary_key" => array(),
            "incremental" => false,
            "delete_where_values" => array(),
            "delete_where_operator" => "eq",
            "delimiter" => ",",
            "enclosure" => "\"",
            "escaped_by" => ""
        );

        $processedConfiguration = (new Table\Manifest())->parse(array("config" => $config));
        $this->assertEquals($expectedArray, $processedConfiguration);
    }

    /**
     *
     */
    public function testComplexConfiguration()
    {
        $config = array(
            "destination" => "in.c-main.test",
            "incremental" => true,
            "primary_key" => array("Id", "Name"),
            "delete_where_column" => "status",
            "delete_where_values" => array("val1", "val2"),
            "delete_where_operator" => "ne",
            "delimiter" => "\t",
            "enclosure" => "'",
            "escaped_by" => "\\"
        );

        $expectedArray = $config;

        $processedConfiguration = (new Table\Manifest())->parse(array("config" => $config));
        $this->assertEquals($expectedArray, $processedConfiguration);
    }


    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage Invalid configuration for path "table.delete_where_operator": Invalid operator in delete_where_operator "abc"
     */
    public function testInvalidWhereOperator()
    {
        $config = array(
            "destination" => "in.c-main.test",
            "delete_where_operator" => 'abc'
        );
        (new Table\Manifest())->parse(array("config" => $config));
    }

    /**
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     * @expectedExceptionMessage The child node "destination" at path "table" must be configured
     */
    public function testEmptyConfiguration()
    {
        (new Table\Manifest())->parse(array("config" => array()));
    }
}
