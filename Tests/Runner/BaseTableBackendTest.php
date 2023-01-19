<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Tests\BaseRunnerTest;

abstract class BaseTableBackendTest extends BaseRunnerTest
{
    use BackendAssertsTrait;

    abstract public static function expectedDefaultTableBackend(): string;

    public function setUp(): void
    {
        parent::setUp();

        self::assertDefaultTableBackend($this::expectedDefaultTableBackend(), $this->client);
    }
}
