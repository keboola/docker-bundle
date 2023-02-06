<?php

declare(strict_types=1);

namespace Docker\OutputFilter;

use Keboola\DockerBundle\Docker\OutputFilter\MinimalFilter;
use PHPUnit\Framework\TestCase;

class NullFilterTest extends TestCase
{
    public function testBrokenUnicode(): void
    {
        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
        $value = 'some text WARNING: Your kernel does not support swap limit capabilities or the cgroup is not mounted. Memory limited without swap. another text';
        $filter = new MinimalFilter();
        self::assertSame('some text  another text', $filter->filter($value));
    }
}
