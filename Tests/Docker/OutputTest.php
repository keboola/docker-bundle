<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Runner\Output;

class OutputTest extends \PHPUnit_Framework_TestCase
{
    public function testAccessors()
    {
        $output = new Output();
        $output->addImages(0, 'apples', ['foo', 'baz']);
        $output->addImages(10, 'oranges', ['bar']);
        $output->addProcessOutput('bazBar');
        self::assertEquals('bazBar', $output->getProcessOutput());
        self::assertEquals(
            [
                0 => ['id' => 'apples', 'digests' => ['foo', 'baz']],
                10 => ['id' => 'oranges', 'digests' => ['bar']]
            ],
            $output->getImages()
        );
    }
}
