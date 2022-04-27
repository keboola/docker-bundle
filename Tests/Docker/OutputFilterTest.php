<?php

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use PHPUnit\Framework\TestCase;

class OutputFilterTest extends TestCase
{
    public function testFilter(): void
    {
        $filter = new OutputFilter(10**6);
        $filter->collectValues(
            [['a' => 'b'], ['c' => ['#d' => 'e'], 'f' => ['g' => '#h', '#i' => 'foo']], '#j' => 'bar']
        );
        self::assertEquals('abcdfghijk', $filter->filter('abcdfghijk'));
        self::assertEquals('abcdFooBarghijk', $filter->filter('abcdFooBarghijk'));
        self::assertEquals('[hidden]', $filter->filter('foo'));
        self::assertEquals(
            'abcd[hidden][hidden]ghijk',
            $filter->filter('abcdefooghijk')
        );
        self::assertEquals(
            'abcd[hidden]gh[hidden][hidden][hidden]k',
            $filter->filter('abcdfooghbarbarfook')
        );
        self::assertEquals(
            "a\n\rbcd[hidden]ghijk",
            $filter->filter("a\n\rbcdfooghijk")
        );
    }

    public function testSemiHiddenValues(): void
    {
        $secret = 'sec\\ret/"sec\'ret';
        $filter = new OutputFilter(10**6);
        $filter->collectValues([['#encrypted' => $secret]]);
        self::assertEquals(
            'this is [hidden] which is secret',
            $filter->filter('this is ' . $secret . ' which is secret')
        );
        self::assertEquals(
            'this is [hidden] which is secret',
            $filter->filter('this is ' . base64_encode($secret) . ' which is secret')
        );
        self::assertEquals(
            'this is [hidden] which is secret',
            $filter->filter('this is ' . json_encode($secret) . ' which is secret')
        );
    }

    public function testBrokenUnicode(): void
    {
        $value = substr('a😀b', 0, 3);
        $filter = new OutputFilter(10**6);
        self::assertSame('a', $filter->filter($value));
    }

    public function testLargeOutput(): void
    {
        $value = str_repeat('😀', 10**6);
        $filter = new OutputFilter(10);
        self::assertSame('😀😀😀😀😀😀😀😀😀😀', $filter->filter($value));
    }
}
