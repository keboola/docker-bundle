<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\DockerBundle\Tests\Docker\ImageTest;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Process\Process;

class AWSElasticContainerRegistryTest extends BaseImageTest
{

    public function testMissingCredentials()
    {
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                ],
            ],
        ]);
        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);
        $image->setRetryLimits(100, 100, 1);
        $this->expectException(LoginFailedException::class);
        $image->prepare([]);
    }

    public function testInvalidCredentials()
    {
        putenv('AWS_ACCESS_KEY_ID=' . getenv('AWS_ECR_ACCESS_KEY_ID') . '_invalid');
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('AWS_ECR_SECRET_ACCESS_KEY'));
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                ],
            ],
        ]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->setRetryLimits(100, 100, 1);
        try {
            $image->prepare([]);
            self::fail('Must raise an exception');
        } catch (LoginFailedException $e) {
            self::assertStringContainsString('Error executing "GetAuthorizationToken"', $e->getMessage());
            self::assertTrue($logger->hasNoticeThatContains('Retrying AWS GetCredentials'));
        }
    }

    public function testDownloadedImage()
    {
        Process::fromShellCommandline(
            'sudo docker rmi -f $(sudo docker images -aq ' . getenv('AWS_ECR_REGISTRY_URI') . ')',
        )->run();
        $process = Process::fromShellCommandline(
            'sudo docker images | grep ' . getenv('AWS_ECR_REGISTRY_URI') . ' | wc -l',
        );
        $process->run();
        self::assertEquals(0, trim($process->getOutput()));

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                ],
            ],
        ]);
        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);
        $image->prepare([]);
        self::assertEquals(getenv('AWS_ECR_REGISTRY_URI') . ':latest', $image->getFullImageId());
        $repoParts = explode('/', getenv('AWS_ECR_REGISTRY_URI'));
        array_shift($repoParts);
        self::assertEquals(implode('/', $repoParts) . ':latest', $image->getPrintableImageId());

        $process = Process::fromShellCommandline(
            'sudo docker images | grep ' . getenv('AWS_ECR_REGISTRY_URI') . '| wc -l',
        );
        $process->run();
        self::assertEquals(1, trim($process->getOutput()));
        Process::fromShellCommandline('sudo docker rmi ' . getenv('AWS_ECR_REGISTRY_URI'));
    }

    public function testGetAwsAccountId(): void
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                ],
            ],
        ]);
        /** @var AWSElasticContainerRegistry $image */
        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);
        self::assertEquals(getenv('AWS_ECR_REGISTRY_ACCOUNT_ID'), $image->getAwsAccountId());
    }

    public function testGetAwsAccountIdInvalid(): void
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => 'invalid',
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                ],
            ],
        ]);
        /** @var AWSElasticContainerRegistry $image */
        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);
        $this->expectExceptionMessage('Invalid image ID format: "invalid".');
        $this->expectException(ApplicationException::class);
        $image->getAwsAccountId();
    }

    public function testLogger(): void
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $testHandler = new TestHandler();
        $logger = new Logger('null', [$testHandler]);
        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->prepare([]);

        self::assertEquals(getenv('AWS_ECR_REGISTRY_URI') . ':test-hash', $image->getFullImageId());
        $repoParts = explode('/', getenv('AWS_ECR_REGISTRY_URI'));
        array_shift($repoParts);
        self::assertEquals(implode('/', $repoParts) . ':test-hash', $image->getPrintableImageId());
        self::assertTrue($testHandler->hasNotice(
            'Using image ' . getenv('AWS_ECR_REGISTRY_URI') .
            ':test-hash with repo-digest ' . getenv('AWS_ECR_REGISTRY_URI') . '@sha256:' .
            ImageTest::TEST_HASH_DIGEST,
        ));
        self::assertEquals(
            [getenv('AWS_ECR_REGISTRY_URI') . '@sha256:' . ImageTest::TEST_HASH_DIGEST],
            $image->getImageDigests(),
        );
        $repoParts = explode('/', getenv('AWS_ECR_REGISTRY_URI'));
        array_shift($repoParts);
        self::assertEquals(
            [implode('/', $repoParts) . '@sha256:' . ImageTest::TEST_HASH_DIGEST],
            $image->getPrintableImageDigests(),
        );
        Process::fromShellCommandline('sudo docker rmi ' . getenv('AWS_ECR_REGISTRY_URI'))->run();
    }
}
