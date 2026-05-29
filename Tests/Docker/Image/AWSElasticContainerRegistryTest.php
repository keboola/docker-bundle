<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\DockerBundle\Tests\Docker\ImageTest;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Symfony\Component\Process\Process;

class AWSElasticContainerRegistryTest extends BaseImageTest
{
    private const TEST_IMAGE_TAG = '0.1.1';

    private static function testImageUri(): string
    {
        return getenv('AWS_ECR_REGISTRY_URI') . '/keboola.runner-staging-test';
    }

    public function testMissingCredentials(): void
    {
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');
        $imageConfig = new ComponentSpecification([
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
        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->setRetryLimits(100, 100, 1);
        $this->expectException(LoginFailedException::class);
        $image->prepare([]);
    }

    public function testInvalidCredentials(): void
    {
        putenv('AWS_ACCESS_KEY_ID=' . getenv('AWS_ECR_ACCESS_KEY_ID') . '_invalid');
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('AWS_ECR_SECRET_ACCESS_KEY'));
        $imageConfig = new ComponentSpecification([
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

        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->setRetryLimits(100, 100, 1);
        try {
            $image->prepare([]);
            self::fail('Must raise an exception');
        } catch (LoginFailedException $e) {
            self::assertStringContainsString('Error executing "GetAuthorizationToken"', $e->getMessage());
            self::assertTrue($this->logsHandler->hasNoticeThatContains('Retrying AWS GetCredentials'));
        }
    }

    public function testImageNotFound(): void
    {
        $imageConfig = new ComponentSpecification([
            'id' => 'test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::testImageUri(),
                    'tag' => 'not-existing',
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                ],
            ],
        ]);
        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->setRetryLimits(100, 100, 1);
        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Image "test:not-existing" not found in the registry.',
        );
        $image->prepare([]);
    }

    public function testDownloadedImage()
    {
        Process::fromShellCommandline(
            'sudo docker rmi -f $(sudo docker images -aq ' . self::testImageUri() . ')',
        )->run();
        $process = Process::fromShellCommandline(
            'sudo docker images | grep ' . self::testImageUri() . ' | wc -l',
        );
        $process->run();
        self::assertEquals(0, trim($process->getOutput()));

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::testImageUri(),
                    'tag' => self::TEST_IMAGE_TAG,
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                ],
            ],
        ]);
        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->prepare([]);
        self::assertEquals(self::testImageUri() . ':' . self::TEST_IMAGE_TAG, $image->getFullImageId());
        $repoParts = explode('/', self::testImageUri());
        array_shift($repoParts);
        self::assertEquals(implode('/', $repoParts) . ':' . self::TEST_IMAGE_TAG, $image->getPrintableImageId());

        $process = Process::fromShellCommandline(
            'sudo docker images | grep ' . self::testImageUri() . '| wc -l',
        );
        $process->run();
        self::assertEquals(1, trim($process->getOutput()));
        Process::fromShellCommandline('sudo docker rmi ' . self::testImageUri());
    }

    public function testGetAwsAccountId(): void
    {
        $imageConfig = new ComponentSpecification([
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
        $image = $this->imageFactory->getImage($imageConfig, true);
        self::assertEquals(getenv('AWS_ECR_REGISTRY_ACCOUNT_ID'), $image->getAwsAccountId());
    }

    public function testGetAwsAccountIdInvalid(): void
    {
        $imageConfig = new ComponentSpecification([
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
        $image = $this->imageFactory->getImage($imageConfig, true);
        $this->expectExceptionMessage('Invalid image ID format: "invalid".');
        $this->expectException(ApplicationException::class);
        $image->getAwsAccountId();
    }

    public function testLogger(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::testImageUri(),
                    'repository' => [
                        'region' => getenv('AWS_ECR_REGISTRY_REGION'),
                    ],
                    'tag' => self::TEST_IMAGE_TAG,
                ],
            ],
        ]);

        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->prepare([]);

        self::assertEquals(self::testImageUri() . ':' . self::TEST_IMAGE_TAG, $image->getFullImageId());
        $repoParts = explode('/', self::testImageUri());
        array_shift($repoParts);
        self::assertEquals(implode('/', $repoParts) . ':' . self::TEST_IMAGE_TAG, $image->getPrintableImageId());
        self::assertTrue($this->logsHandler->hasNotice(
            'Using image ' . self::testImageUri() .
            ':' . self::TEST_IMAGE_TAG . ' with repo-digest ' . self::testImageUri() . '@sha256:' .
            ImageTest::TEST_HASH_DIGEST,
        ));
        self::assertEquals(
            [self::testImageUri() . '@sha256:' . ImageTest::TEST_HASH_DIGEST],
            $image->getImageDigests(),
        );
        $repoParts = explode('/', self::testImageUri());
        array_shift($repoParts);
        self::assertEquals(
            [implode('/', $repoParts) . '@sha256:' . ImageTest::TEST_HASH_DIGEST],
            $image->getPrintableImageDigests(),
        );
        Process::fromShellCommandline('sudo docker rmi ' . self::testImageUri())->run();
    }
}
