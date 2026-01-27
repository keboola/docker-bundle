<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

abstract class BaseImageTest extends TestCase
{
    use TestEnvVarsTrait;

    private ObjectEncryptor $encryptor;
    protected readonly TestHandler $logsHandler;
    protected readonly LoggerInterface $logger;
    protected readonly ImageFactory $imageFactory;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . self::getOptionalEnv('AWS_ECR_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . self::getOptionalEnv('AWS_ECR_SECRET_ACCESS_KEY'));

        $stackId = parse_url(self::getRequiredEnv('STORAGE_API_URL'), PHP_URL_HOST);
        self::assertNotEmpty($stackId);

        $this->encryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
            $stackId,
            self::getRequiredEnv('AWS_KMS_TEST_KEY'),
            self::getRequiredEnv('AWS_ECR_REGISTRY_REGION'),
            null,
            null,
        ));

        $this->logsHandler = new TestHandler();
        $this->logger = new Logger('test', [$this->logsHandler]);

        $this->imageFactory = new ImageFactory($this->logger);
    }

    protected function getEncryptor(): ObjectEncryptor
    {
        return $this->encryptor;
    }

    protected function getJobScopedEncryptor(
        string $componentId = 'foo',
        string $projectId = 'bar',
        ?string $configId = null,
    ): JobScopedEncryptor {
        return new JobScopedEncryptor(
            $this->encryptor,
            $componentId,
            $projectId,
            $configId,
            ObjectEncryptor::BRANCH_TYPE_DEFAULT,
            [],
        );
    }
}
