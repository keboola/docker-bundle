<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\JobDefinitionParser;

class JobDefinitionParserTest extends \PHPUnit_Framework_TestCase
{



    public function testSimpleConfigData()
    {
        $configData = [

        ]
        $parser = new JobDefinitionParser();
        $parser->parseConfigData();


        $this->assertCount(1, $parser->getConfigs())
    }
}
