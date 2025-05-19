<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use PHPUnit\Framework\TestCase;

class OutputFilterTest extends TestCase
{
    public function testFilter(): void
    {
        $filter = new OutputFilter(10**6);
        $filter->collectValues(
            [['a' => 'b'], ['c' => ['#d' => 'e'], 'f' => ['g' => '#h', '#i' => 'foo']], '#j' => 'bar'],
        );
        self::assertEquals('abcdfghijk', $filter->redactSecrets('abcdfghijk'));
        self::assertEquals('abcdFooBarghijk', $filter->redactSecrets('abcdFooBarghijk'));
        self::assertEquals('[hidden]', $filter->redactSecrets('foo'));
        self::assertEquals(
            'abcd[hidden][hidden]ghijk',
            $filter->redactSecrets('abcdefooghijk'),
        );
        self::assertEquals(
            'abcd[hidden]gh[hidden][hidden][hidden]k',
            $filter->redactSecrets('abcdfooghbarbarfook'),
        );
        self::assertEquals(
            "a\n\rbcd[hidden]ghijk",
            $filter->redactSecrets("a\n\rbcdfooghijk"),
        );
    }

    public function testSemiHiddenValues(): void
    {
        $secret = 'sec\\ret/"sec\'ret';
        $filter = new OutputFilter(10**6);
        $filter->collectValues([['#encrypted' => $secret]]);
        self::assertEquals(
            'this is [hidden] which is secret',
            $filter->redactSecrets('this is ' . $secret . ' which is secret'),
        );
        self::assertEquals(
            'this is [hidden] which is secret',
            $filter->redactSecrets('this is ' . base64_encode($secret) . ' which is secret'),
        );
        self::assertEquals(
            'this is [hidden] which is secret',
            $filter->redactSecrets('this is ' . json_encode($secret) . ' which is secret'),
        );
    }

    public function testBrokenUnicode(): void
    {
        $value = substr('a😀b', 0, 3);
        $filter = new OutputFilter(10**6);
        self::assertSame('a', $filter->redactSecrets($value));
    }

    public function testLargeOutput(): void
    {
        $value = str_repeat('😀', 10**6);
        $filter = new OutputFilter(10);
        self::assertSame('😀😀😀😀😀😀😀😀😀😀 [trimmed]', $filter->redactSecrets($value));
    }

    public function testPartialSecrets(): void
    {
        $filter = new OutputFilter(13);
        $filter->collectValues([['#encrypted' => 'secret']]);
        self::assertEquals(
            'this is [hidd [trimmed]',
            $filter->redactSecrets('this is secret which is hidden'),
        );
    }
}
