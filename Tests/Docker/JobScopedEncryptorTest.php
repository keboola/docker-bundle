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
        string $methodCalled,
        string $methodNotCalled,
        string $method,
    ): void {
        $objectEncryptorMock = $this->createMock(ObjectEncryptor::class);
        $objectEncryptorMock
            ->expects(self::once())
            ->method($methodCalled)
            ->with(
                'my-data',
                ... $arguments
            )
            ->willReturn('my-data');
        $objectEncryptorMock
            ->expects(self::never())
            ->method($methodNotCalled);

        $jobScopedEncryptor = new JobScopedEncryptor(
            $objectEncryptorMock,
            ... $arguments,
        );
        $jobScopedEncryptor->$method('my-data');
    }

    public function provideArgumentsWithoutConfig(): Generator
    {
        yield 'encrypt without configuration' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => null,
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'encryptForBranchType',
            'methodNotCalled' => 'encryptForBranchTypeConfiguration',
            'method' => 'encrypt',
        ];
        yield 'decrypt without configuration' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => null,
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'decryptForBranchType',
            'methodNotCalled' => 'decryptForBranchTypeConfiguration',
            'method' => 'decrypt',
        ];
        yield 'encrypt with configuration' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'encryptForBranchTypeConfiguration',
            'methodNotCalled' => 'encryptForBranchType',
            'method' => 'encrypt',
        ];
        yield 'decrypt with configuration' => [
            'arguments' => [
                'componentId' => 'my-component',
                'projectId' => 'my-project',
                'configId' => 'my-config',
                'branchType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            ],
            'methodCalled' => 'decryptForBranchTypeConfiguration',
            'methodNotCalled' => 'decryptForBranchType',
            'method' => 'decrypt',
        ];
    }
}
