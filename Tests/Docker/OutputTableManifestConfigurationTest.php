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
            "columns" => array(),
            "incremental" => false,
            "delete_where_values" => array(),
            "delete_where_operator" => "eq",
            "delimiter" => ",",
            "enclosure" => "\"",
            "escaped_by" => "",
            "metadata" => array(),
            "columnMetadata" => array()
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
            "columns" => array("Id", "Name", "status"),
            "delete_where_column" => "status",
            "delete_where_values" => array("val1", "val2"),
            "delete_where_operator" => "ne",
            "delimiter" => "\t",
            "enclosure" => "'",
            "escaped_by" => "\\",
            "metadata" => array(),
            "columnMetadata" => array()
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

    public function testTableMetadataConfiguration()
    {
        $config = array(
            "destination" => "in.c-main.test",
            "metadata" => [
                [
                    "key" => "table.key.one",
                    "value" => "table value one"
                ],
                [
                    "key" => 'table.key.two',
                    "value" => "table value two"
                ]
            ]
        );

        $expectedArray = array(
            "destination" => "in.c-main.test",
            "primary_key" => array(),
            "columns" => array(),
            "incremental" => false,
            "delete_where_values" => array(),
            "delete_where_operator" => "eq",
            "delimiter" => ",",
            "enclosure" => "\"",
            "escaped_by" => "",
            "columnMetadata" => array()
        );
        $expectedArray['metadata'] = $config['metadata'];

        $parsedConfig = (new Table\Manifest())->parse(array("config" => $config));

        $this->assertEquals($expectedArray, $parsedConfig);
    }

    public function testColumnMetadataConfiguration()
    {
        $config = array(
            "destination" => "in.c-main.test",
            "columnMetadata" => [
                "colA" => [
                    [
                        "key" => "column.key.one",
                        "value" => "column value A"
                    ],
                    [
                        "key" => "column.key.two",
                        "value" => "column value A"
                    ]
                ],
                "colB" => [
                    [
                        "key" => "column.key.one",
                        "value" => "column value B"
                    ],
                    [
                        "key" => "column.key.two",
                        "value" => "column value B"
                    ]
                ]
            ]
        );

        $expectedArray = array(
            "destination" => "in.c-main.test",
            "primary_key" => array(),
            "columns" => array(),
            "incremental" => false,
            "delete_where_values" => array(),
            "delete_where_operator" => "eq",
            "delimiter" => ",",
            "enclosure" => "\"",
            "escaped_by" => "",
            "metadata" => array()
        );

        $expectedArray['columnMetadata'] = $config['columnMetadata'];

        $parsedConfig = (new Table\Manifest())->parse(array("config" => $config));

        $this->assertEquals($expectedArray, $parsedConfig);
    }
}
