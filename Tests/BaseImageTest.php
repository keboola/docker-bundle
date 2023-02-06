<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use PHPUnit\Framework\TestCase;

abstract class BaseImageTest extends TestCase
{
    private ObjectEncryptor $encryptor;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . getenv('AWS_ECR_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('AWS_ECR_SECRET_ACCESS_KEY'));

        $this->encryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
            parse_url((string) getenv('STORAGE_API_URL'), PHP_URL_HOST),
            getenv('AWS_KMS_TEST_KEY'),
            getenv('AWS_ECR_REGISTRY_REGION'),
            null,
            null,
        ));
    }

    protected function getEncryptor(): ObjectEncryptor
    {
        return $this->encryptor;
    }

    protected function getJobScopedEncryptor(
        string $componentId = 'foo',
        string $projectId = 'bar',
        ?string $configId = null
    ): JobScopedEncryptor {
        return new JobScopedEncryptor($this->encryptor, $componentId, $projectId, $configId);
    }
}
