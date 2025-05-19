<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Runner\DataLoader\NullDataLoader;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use PHPUnit\Framework\TestCase;

class NullDataLoaderTest extends TestCase
{
    public function testAccessors()
    {
        $dataLoader = new NullDataLoader();
        self::assertSame([], $dataLoader->getWorkspaceCredentials());
        $result = $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
        self::assertNotNull($result->getInputFileStateList());
        self::assertNotNull($result->getInputTableResult());
        self::assertNotNull($result->getInputTableResult()->getInputTableStateList());
    }
}
