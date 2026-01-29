<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\Image\ReplicatedRegistry;
use Keboola\DockerBundle\Docker\Image\ReplicatedRegistryImage;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ImageFactoryTest extends TestCase
{
    private const ECR_URI = '147946154733.dkr.ecr.us-east-1.amazonaws.com/keboola/test-component';
    private const REPLICATED_REGISTRY_URL = 'us-docker.pkg.dev/my-project/my-repo';

    public function testCreatesReplicatedRegistryImageWhenEnabled(): void
    {
        $service = new ReplicatedRegistry(
            true,
            self::REPLICATED_REGISTRY_URL,
            'testuser',
            'testpass',
        );
        $imageFactory = new ImageFactory(new NullLogger(), $service);

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = $imageFactory->getImage($imageConfig, true);

        self::assertInstanceOf(ReplicatedRegistryImage::class, $image);
    }

    public function testCreatesEcrWhenNotEnabled(): void
    {
        $service = new ReplicatedRegistry(
            false,
            'dummy-registry-url',
            'dummy-user',
            'dummy-pass',
        );
        $imageFactory = new ImageFactory(new NullLogger(), $service);

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $image = $imageFactory->getImage($imageConfig, true);

        self::assertInstanceOf(AWSElasticContainerRegistry::class, $image);
    }
}
