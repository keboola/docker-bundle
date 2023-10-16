<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\JobDefinition;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use PHPUnit\Framework\TestCase;

class JobDefinitionTest extends TestCase
{
    public function testDefaults(): void
    {
        $componentMock = $this->createMock(Component::class);
        $componentMock->expects(self::once())
            ->method('getId')
            ->willReturn('keboola.sandboxes')
        ;

        $jobDefinition = new JobDefinition(
            [
                'parameters' => [
                    'foo' => 'bar',
                ],
            ],
            $componentMock,
        );

        self::assertSame('default', $jobDefinition->getBranchType());
        self::assertSame($componentMock, $jobDefinition->getComponent());
        self::assertSame('keboola.sandboxes', $jobDefinition->getComponentId());
        self::assertNull($jobDefinition->getConfigId());
        self::assertSame(
            [
                'parameters' => [
                    'foo' => 'bar',
                ],
                'shared_code_row_ids' => [],
                'storage' => [],
                'processors' => [],
            ],
            $jobDefinition->getConfiguration(),
        );
        self::assertNull($jobDefinition->getConfigVersion());
        self::assertNull($jobDefinition->getRowId());
        self::assertSame([], $jobDefinition->getState());
        self::assertFalse($jobDefinition->isDisabled());
        self::assertNull($jobDefinition->getInputVariableValues());
    }
    public function testGetters(): void
    {
        $componentMock = $this->createMock(Component::class);
        $componentMock->expects(self::once())
            ->method('getId')
            ->willReturn('keboola.sandboxes')
        ;

        $jobDefinition = new JobDefinition(
            [
                'parameters' => [
                    'foo' => 'bar',
                ],
            ],
            $componentMock,
            '123',
            '456',
            [
                'time' => [
                    'previousStart' => 1587980435,
                ],
            ],
            '789',
            true,
            ObjectEncryptor::BRANCH_TYPE_DEV,
            [
                'vault.foo' => 'vault bar',
                'foo' => 'bar',
            ],
        );

        self::assertSame('dev', $jobDefinition->getBranchType());
        self::assertSame($componentMock, $jobDefinition->getComponent());
        self::assertSame('keboola.sandboxes', $jobDefinition->getComponentId());
        self::assertSame('123', $jobDefinition->getConfigId());
        self::assertSame(
            [
                'parameters' => [
                    'foo' => 'bar',
                ],
                'shared_code_row_ids' => [],
                'storage' => [],
                'processors' => [],
            ],
            $jobDefinition->getConfiguration(),
        );
        self::assertSame('456', $jobDefinition->getConfigVersion());
        self::assertSame('789', $jobDefinition->getRowId());
        self::assertSame(
            [
                'time' => [
                    'previousStart' => 1587980435,
                ],
            ],
            $jobDefinition->getState(),
        );
        self::assertTrue($jobDefinition->isDisabled());
        self::assertSame(
            [
                'vault.foo' => 'vault bar',
                'foo' => 'bar',
            ],
            $jobDefinition->getInputVariableValues(),
        );
    }
}
