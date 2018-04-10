<?php

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use PHPUnit\Framework\TestCase;

class OutputFilterTest extends TestCase
{
    public function testFilter()
    {
        $filter = new OutputFilter();
        $filter->collectValues(
            [['a' => 'b'], ['c' => ['#d' => 'e'], 'f' => ['g' => '#h', '#i' => 'foo']], '#j' => 'bar']
        );
        self::assertEquals('abcdfghijk', $filter->filter('abcdfghijk'));
        self::assertEquals('abcdFooBarghijk', $filter->filter('abcdFooBarghijk'));
        self::assertEquals(OutputFilter::REPLACEMENT, $filter->filter('foo'));
        self::assertEquals(
            'abcd' . OutputFilter::REPLACEMENT . OutputFilter::REPLACEMENT . 'ghijk',
            $filter->filter('abcdefooghijk')
        );
        self::assertEquals(
            'abcd' . OutputFilter::REPLACEMENT . 'gh' . OutputFilter::REPLACEMENT .
            OutputFilter::REPLACEMENT . OutputFilter::REPLACEMENT . 'k',
            $filter->filter('abcdfooghbarbarfook')
        );
        self::assertEquals(
            "a\n\rbcd" . OutputFilter::REPLACEMENT . 'ghijk',
            $filter->filter("a\n\rbcdfooghijk")
        );
    }

    public function testFunctions()
    {
        $secret = 'sec\\ret/"sec\'ret';
        $filter = new OutputFilter();
        $filter->collectValues([['#encrypted' => $secret]]);
        self::assertEquals(
            'this is ' . OutputFilter::REPLACEMENT . ' which is secret',
            $filter->filter('this is ' . $secret . ' which is secret')
        );
        self::assertEquals(
            'this is ' . OutputFilter::REPLACEMENT . ' which is secret',
            $filter->filter('this is ' . base64_encode($secret) . ' which is secret')
        );
        self::assertEquals(
            'this is ' . OutputFilter::REPLACEMENT . ' which is secret',
            $filter->filter('this is ' . json_encode($secret) . ' which is secret')
        );
    }
}
