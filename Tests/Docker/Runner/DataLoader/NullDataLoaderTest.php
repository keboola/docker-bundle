<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Runner\DataLoader;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\DataLoader\NullDataLoader;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\StorageApiBranch\ClientWrapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NullDataLoaderTest extends TestCase
{
    public function testAccessors()
    {
        $clientWrapper = self::createMock(ClientWrapper::class);
        $component = new Component([
            'id' => 'keboola.dummy',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => 'keboola/dummy',
                    'tag' => 'latest',
                ],
                'staging-storage' => [
                    'input' => 'workspace-abs',
                    'output' => 'workspace-abs',
                ],
            ],
        ]);
        /** @var ClientWrapper $clientWrapper */
        $dataLoader = new NullDataLoader(
            $clientWrapper,
            new NullLogger(),
            '',
            new JobDefinition([], $component),
            new OutputFilter(10**6),
        );
        self::assertSame([], $dataLoader->getWorkspaceCredentials());
        $result = $dataLoader->loadInputData(new InputTableStateList([]), new InputFileStateList([]));
        self::assertNotNull($result->getInputFileStateList());
        self::assertNotNull($result->getInputTableResult());
        self::assertNotNull($result->getInputTableResult()->getInputTableStateList());
    }
}
