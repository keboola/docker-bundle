<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Image\ReplicatedRegistry;
use Keboola\DockerBundle\Docker\Image\ReplicatedRegistryImage;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReplicatedRegistryImageTest extends TestCase
{
    private const ECR_URI = '147946154733.dkr.ecr.us-east-1.amazonaws.com/keboola/test-component';
    private const REPLICATED_REGISTRY_URL = 'us-docker.pkg.dev/my-project/my-repo';

    public static function provideImageIdTransformationData(): iterable
    {
        yield 'transforms ECR URL to GAR URL' => [
            'enabled' => true,
            'replicatedRegistryUrl' => self::REPLICATED_REGISTRY_URL,
            'expectedImageId' => 'us-docker.pkg.dev/my-project/my-repo/keboola/test-component',
        ];

        yield 'transforms ECR URL to ACR URL' => [
            'enabled' => true,
            'replicatedRegistryUrl' => 'myregistry.azurecr.io',
            'expectedImageId' => 'myregistry.azurecr.io/keboola/test-component',
        ];

        yield 'transforms URL even when service disabled (URL is set)' => [
            'enabled' => false,
            'replicatedRegistryUrl' => self::REPLICATED_REGISTRY_URL,
            'expectedImageId' => 'us-docker.pkg.dev/my-project/my-repo/keboola/test-component',
        ];

        yield 'returns original ECR URL when replicated URL is empty' => [
            'enabled' => true,
            'replicatedRegistryUrl' => '',
            'expectedImageId' => self::ECR_URI,
        ];
    }

    /** @dataProvider provideImageIdTransformationData */
    public function testGetImageIdTransformation(
        bool $enabled,
        string $replicatedRegistryUrl,
        string $expectedImageId,
    ): void {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $service = new ReplicatedRegistry(
            $enabled,
            $replicatedRegistryUrl,
            'testuser',
            'testpass',
        );

        $image = new ReplicatedRegistryImage(
            $imageConfig,
            new NullLogger(),
            $service,
        );

        self::assertEquals($expectedImageId, $image->getImageId());
    }

    public function testGetFullImageIdIncludesTag(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                    'tag' => 'v1.0.0',
                ],
            ],
        ]);

        $service = new ReplicatedRegistry(
            true,
            self::REPLICATED_REGISTRY_URL,
            'testuser',
            'testpass',
        );

        $image = new ReplicatedRegistryImage(
            $imageConfig,
            new NullLogger(),
            $service,
        );

        self::assertEquals(
            'us-docker.pkg.dev/my-project/my-repo/keboola/test-component:v1.0.0',
            $image->getFullImageId(),
        );
    }
}
