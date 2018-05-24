<?php

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Runner\Output;
use PHPUnit\Framework\TestCase;

class OutputTest extends TestCase
{
    public function testAccessors()
    {
        $images = [
            ['id' => 'apples', 'digests' => ['foo', 'baz']],
            ['id' => 'oranges', 'digests' => ['bar']]
        ];
        $output = new Output($images, 'bazBar', '123');
        self::assertEquals('bazBar', $output->getProcessOutput());
        self::assertEquals(
            [
                0 => ['id' => 'apples', 'digests' => ['foo', 'baz']],
                1 => ['id' => 'oranges', 'digests' => ['bar']]
            ],
            $output->getImages()
        );
        self::assertEquals('123', $output->getConfigVersion());
    }
}
