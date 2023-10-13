<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\DockerBundle\Docker\Image\QuayIO;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Process\Process;

class ImageTest extends BaseImageTest
{
    public const TEST_HASH_DIGEST = 'a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a';

    public function testDockerHub()
    {
        $configuration = new Component([
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master',
                ],
            ],
        ]);

        $image = ImageFactory::getImage(new NullLogger(), $configuration, true);
        self::assertEquals(DockerHub::class, get_class($image));
        self::assertEquals('master', $image->getTag());
        self::assertEquals('keboola/docker-demo:master', $image->getFullImageId());
    }

    public function testQuayIO()
    {
        $configuration = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
            ],
        ]);

        $image = ImageFactory::getImage(new NullLogger(), $configuration, true);
        self::assertEquals(QuayIO::class, get_class($image));
        self::assertEquals('quay.io/keboola/docker-demo-app:latest', $image->getFullImageId());
        self::assertEquals('keboola/docker-demo-app:latest', $image->getPrintableImageId());
    }

    public function testImageDigestNotPulled()
    {
        $command = Process::fromShellCommandline('sudo docker rmi ' . getenv('AWS_ECR_REGISTRY_URI') . ':test-hash');
        $command->run();
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->prepare([]);
        self::assertTrue($logger->hasNoticeThatContains(
            'Digest "a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a" for image ' .
            '"' . getenv('AWS_ECR_REGISTRY_URI') .':test-hash" not found.',
        ));
    }

    public function testImageDigestPulled()
    {

        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);
        $image->prepare([]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->prepare([]);
        self::assertFalse($logger->hasNoticeThatContains(
            'Digest "a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a" for image ' .
            '"' . getenv('AWS_ECR_REGISTRY_URI') .':test-hash" not found.',
        ));
    }

    public function testImageDigestInvalid()
    {
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'latest',
                ],
            ],
        ]);
        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);
        $image->prepare([]);
        preg_match('#@sha256:(.*)$#', $image->getImageDigests()[0], $matches);
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'digest' => $matches[1],
                    'tag' => 'test-hash',
                ],
            ],
        ]);
        $logger = new TestLogger();
        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->prepare([]);
        self::assertTrue($logger->hasNoticeThatContains(
            'Digest "' . $matches[1] . '" for image ' .
            '"' . getenv('AWS_ECR_REGISTRY_URI') .':test-hash" not found.',
        ));
    }
}
