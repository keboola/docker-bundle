<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Generator;
use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use PHPUnit\Framework\TestCase;

class JobScopedEncryptorTest extends TestCase
{

    /** @dataProvider provideArgumentsWithoutConfig */
    public function testEncryptDecryptWithoutConfig(
        array $arguments,
        array $expectedArguments,
        string $methodCalled,
        string $method,
    ): void {
        $objectEncryptorMock = $this->createMock(ObjectEncryptor::class);
        $objectEncryptorMock
            ->expects(self::once())
            ->method($methodCalled)
            ->with(
                'my-data',
                ... $expectedArguments,
            )
            ->willReturn('my-data');

        $jobScopedEncryptor = new JobScopedEncryptor(
            $objectEncryptorMock,
            ... $arguments,
        );
        $jobScopedEncryptor->$method('my-data');
    }

    public function provideArgumentsWithoutConfig(): Generator
    {
        yield 'encrypt without configuration without feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => null,
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => [],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
            ],
            'methodCalled' => 'encryptForProject',
            'method' => 'encrypt',
        ];
        yield 'decrypt without configuration without feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => null,
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => [],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'decryptForBranchType',
            'method' => 'decrypt',
        ];
        yield 'encrypt with configuration without feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => [],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
            ],
            'methodCalled' => 'encryptForProject',
            'method' => 'encrypt',
        ];
        yield 'decrypt with configuration without feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => [],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'decryptForBranchTypeConfiguration',
            'method' => 'decrypt',
        ];
        yield 'encrypt without configuration with feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => null,
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => ['protected-default-branch'],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'encryptForBranchType',
            'method' => 'encrypt',
        ];
        yield 'decrypt without configuration with feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => null,
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => ['protected-default-branch'],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'decryptForBranchType',
            'method' => 'decrypt',
        ];
        yield 'encrypt with configuration with feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => ['protected-default-branch'],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'encryptForBranchType',
            'method' => 'encrypt',
        ];
        yield 'decrypt with configuration with feature' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
                'projectFeatures' => ['protected-default-branch'],
            ],
            'expectedArguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'decryptForBranchTypeConfiguration',
            'method' => 'decrypt',
        ];
    }
}
