<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Container\Options;
use Keboola\DockerBundle\Exception\ContainerOptionsException;

class ContainerOptionsTest extends \PHPUnit_Framework_TestCase
{
    public function testValidOptions()
    {
        $options = new Options([
            'name' => 'container-name',
            'label' => [
                'com.keboola.runId=10.20.30',
                'com.keboola.jobId=12345678',
            ],
            'env' => [
                'environment=dev'
            ],
        ]);

        $expected = " --name 'container-name'"
            . " --label 'com.keboola.runId=10.20.30' --label 'com.keboola.jobId=12345678'"
            . " --env 'environment=dev'";

        $actual = $options->getOptionAsShellArg('name')
            . $options->getOptionAsShellArg('label')
            . $options->getOptionAsShellArg('env');

        $this->assertEquals($expected, $actual);
    }

    public function testInvalidValidOptions()
    {
        $this->expectException(ContainerOptionsException::class);
        $this->expectExceptionMessage(
            'Specified option not exists or is not scalar type'
        );

        new Options([
            'env' => 'environment=dev',
        ]);
    }
}
