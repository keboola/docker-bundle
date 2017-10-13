<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Runner\Output;

class OutputTest extends \PHPUnit_Framework_TestCase
{
    public function testAccessors()
    {
        $images = [
            ['id' => 'apples', 'digests' => ['foo', 'baz']],
            ['id' => 'oranges', 'digests' => ['bar']]
        ];
        $output = new Output($images, 'bazBar');
        self::assertEquals('bazBar', $output->getProcessOutput());
        self::assertEquals(
            [
                0 => ['id' => 'apples', 'digests' => ['foo', 'baz']],
                1 => ['id' => 'oranges', 'digests' => ['bar']]
            ],
            $output->getImages()
        );
    }
}
