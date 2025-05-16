<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\DockerBundle\Docker\Image\QuayIO;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class ImageTest extends BaseImageTest
{
    public const TEST_HASH_DIGEST = 'a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a';

    public function testDockerHub()
    {
        $configuration = new ComponentSpecification([
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
        $configuration = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-bundle-ci',
                ],
            ],
        ]);

        $image = ImageFactory::getImage(new NullLogger(), $configuration, true);
        self::assertEquals(QuayIO::class, get_class($image));
        self::assertEquals('quay.io/keboola/docker-bundle-ci:latest', $image->getFullImageId());
        self::assertEquals('keboola/docker-bundle-ci:latest', $image->getPrintableImageId());
    }

    public function testImageDigestNotPulled()
    {
        $command = Process::fromShellCommandline('sudo docker rmi ' . getenv('AWS_ECR_REGISTRY_URI') . ':test-hash');
        $command->run();
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'test-hash',
                ],
            ],
        ]);

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->prepare([]);
        self::assertTrue($logsHandler->hasNoticeThatContains(
            'Digest "a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a" for image ' .
            '"' . getenv('AWS_ECR_REGISTRY_URI') .':test-hash" not found.',
        ));
    }

    public function testImageDigestPulled()
    {

        $imageConfig = new ComponentSpecification([
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

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->prepare([]);
        self::assertFalse($logsHandler->hasNoticeThatContains(
            'Digest "a89486bee7cadd59a966500cd837e0cea70a7989de52636652ae9fccfc958c9a" for image ' .
            '"' . getenv('AWS_ECR_REGISTRY_URI') .':test-hash" not found.',
        ));
    }

    public function testImageDigestInvalid()
    {
        $imageConfig = new ComponentSpecification([
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
        self::assertCount(2, $matches);
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                    'digest' => $matches[1],
                    'tag' => 'test-hash',
                ],
            ],
        ]);

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $image = ImageFactory::getImage($logger, $imageConfig, true);
        $image->prepare([]);
        self::assertTrue($logsHandler->hasNoticeThatContains(
            'Digest "' . $matches[1] . '" for image ' .
            '"' . getenv('AWS_ECR_REGISTRY_URI') .':test-hash" not found.',
        ));
    }

    public static function provideProcessTimeoutTestData(): iterable
    {
        yield 'use component timeout by default' => [
            'componentTimeout' => 20,
            'configTimeout' => null,
            'isMain' => true,
            'expectedTimeout' => 20,
        ];

        yield 'use custom timeout when specified' => [
            'componentTimeout' => 20,
            'configTimeout' => 10,
            'isMain' => true,
            'expectedTimeout' => 10,
        ];

        yield 'use custom timeout when specified (larger than component)' => [
            'componentTimeout' => 20,
            'configTimeout' => 40,
            'isMain' => true,
            'expectedTimeout' => 40,
        ];

        yield 'custom timeout is capped to 24 hours' => [
            'componentTimeout' => 20,
            'configTimeout' => 25 * 60 * 60,
            'isMain' => true,
            'expectedTimeout' => 24 * 60 * 60,
        ];

        yield 'cusotm timeout applies only for main component' => [
            'componentTimeout' => 20,
            'configTimeout' => 10,
            'isMain' => false,
            'expectedTimeout' => 20,
        ];
    }

    /** @dataProvider provideProcessTimeoutTestData */
    public function testGetProcessTimeout(
        int $componentTimeout,
        ?int $configTimeout,
        bool $isMain,
        int $expectedTimeout,
    ): void {
        $image = ImageFactory::getImage(
            new Logger('test'),
            new ComponentSpecification([
                'data' => [
                    'process_timeout' => $componentTimeout,
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => getenv('AWS_ECR_REGISTRY_URI'),
                        'digest' => self::TEST_HASH_DIGEST,
                        'tag' => 'test-hash',
                    ],
                ],
            ]),
            $isMain,
        );
        $image->prepare([
            'runtime' => [
                'process_timeout' => $configTimeout,
            ],
        ]);

        $timeout = $image->getProcessTimeout();
        self::assertSame($expectedTimeout, $timeout);
    }
}
