<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Tests\BaseRunnerTest;

abstract class BaseTableBackendTest extends BaseRunnerTest
{
    use BackendAssertsTrait;

    abstract public static function expectedDefaultTableBackend(): string;

    public function setUp(): void
    {
        if (!constant(sprintf('RUN_%s_TESTS', mb_strtoupper($this::expectedDefaultTableBackend())))) {
            self::markTestSkipped(sprintf('%s tests are disabled.', mb_convert_case($this::expectedDefaultTableBackend(), MB_CASE_TITLE)));
        }

        parent::setUp();

        self::assertDefaultTableBackend($this::expectedDefaultTableBackend(), $this->client);
    }
}
