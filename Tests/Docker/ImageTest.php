<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\DockerBundle\Docker\Image\QuayIO;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Symfony\Component\Process\Process;

class ImageTest extends BaseImageTest
{
    public const TEST_HASH_DIGEST = 'f44de9927422695f478e6a9713f2dd21f6951b6f7cddbdb10500c2b720137042';
    private const TEST_IMAGE_TAG = '0.1.1';

    private static function testImageUri(): string
    {
        return getenv('AWS_ECR_REGISTRY_URI') . '/keboola.runner-staging-test';
    }

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

        $image = $this->imageFactory->getImage($configuration, true);
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

        $image = $this->imageFactory->getImage($configuration, true);
        self::assertEquals(QuayIO::class, get_class($image));
        self::assertEquals('quay.io/keboola/docker-bundle-ci:latest', $image->getFullImageId());
        self::assertEquals('keboola/docker-bundle-ci:latest', $image->getPrintableImageId());
    }

    public function testImageDigestNotPulled()
    {
        $command = Process::fromShellCommandline(
            'sudo docker rmi ' . self::testImageUri() . ':' . self::TEST_IMAGE_TAG,
        );
        $command->run();
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::testImageUri(),
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => self::TEST_IMAGE_TAG,
                ],
            ],
        ]);

        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->prepare([]);
        self::assertTrue($this->logsHandler->hasNoticeThatContains(
            'Digest "' . self::TEST_HASH_DIGEST . '" for image ' .
            '"' . self::testImageUri() . ':' . self::TEST_IMAGE_TAG . '" not found.',
        ));
    }

    public function testImageDigestPulled()
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::testImageUri(),
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => self::TEST_IMAGE_TAG,
                ],
            ],
        ]);
        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->prepare([]);

        // Reset logs from first prepare
        $this->logsHandler->clear();

        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->prepare([]);
        self::assertFalse($this->logsHandler->hasNoticeThatContains(
            'Digest "' . self::TEST_HASH_DIGEST . '" for image ' .
            '"' . self::testImageUri() . ':' . self::TEST_IMAGE_TAG . '" not found.',
        ));
    }

    public function testImageDigestInvalid()
    {
        // Pull a different image to obtain a real digest that does not belong to the tested tag.
        $otherImageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => getenv('AWS_ECR_REGISTRY_URI') . '/keboola.runner-workspace-test',
                    'digest' => self::TEST_HASH_DIGEST,
                    'tag' => 'latest',
                ],
            ],
        ]);
        $image = $this->imageFactory->getImage($otherImageConfig, true);
        $image->prepare([]);
        preg_match('#@sha256:(.*)$#', $image->getImageDigests()[0], $matches);
        self::assertCount(2, $matches);
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::testImageUri(),
                    'digest' => $matches[1],
                    'tag' => self::TEST_IMAGE_TAG,
                ],
            ],
        ]);

        // Reset logs from first prepare
        $this->logsHandler->clear();

        $image = $this->imageFactory->getImage($imageConfig, true);
        $image->prepare([]);
        self::assertTrue($this->logsHandler->hasNoticeThatContains(
            'Digest "' . $matches[1] . '" for image ' .
            '"' . self::testImageUri() . ':' . self::TEST_IMAGE_TAG . '" not found.',
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
        $image = $this->imageFactory->getImage(
            new ComponentSpecification([
                'data' => [
                    'process_timeout' => $componentTimeout,
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => self::testImageUri(),
                        'digest' => self::TEST_HASH_DIGEST,
                        'tag' => self::TEST_IMAGE_TAG,
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
