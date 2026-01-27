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

    public function testGetImageIdTransformsUrl(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
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
            'us-docker.pkg.dev/my-project/my-repo/keboola/test-component',
            $image->getImageId(),
        );
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

    public function testGetImageIdWithAcrUrl(): void
    {
        $acrUrl = 'myregistry.azurecr.io';

        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $service = new ReplicatedRegistry(
            true,
            $acrUrl,
            'testuser',
            'testpass',
        );

        $image = new ReplicatedRegistryImage(
            $imageConfig,
            new NullLogger(),
            $service,
        );

        self::assertEquals(
            'myregistry.azurecr.io/keboola/test-component',
            $image->getImageId(),
        );
    }

    public function testImageIdTransformationWhenServiceDisabled(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $service = new ReplicatedRegistry(
            false,
            self::REPLICATED_REGISTRY_URL,
            'testuser',
            'testpass',
        );

        $image = new ReplicatedRegistryImage(
            $imageConfig,
            new NullLogger(),
            $service,
        );

        // transformImageUrl() checks for non-empty URL, not isEnabled()
        // So transformation still happens when URL is set
        self::assertEquals(
            'us-docker.pkg.dev/my-project/my-repo/keboola/test-component',
            $image->getImageId(),
        );
    }

    public function testImageIdTransformationWithEmptyReplicatedUrl(): void
    {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                ],
            ],
        ]);

        $service = new ReplicatedRegistry(
            true,
            '',
            'testuser',
            'testpass',
        );

        $image = new ReplicatedRegistryImage(
            $imageConfig,
            new NullLogger(),
            $service,
        );

        // When replicated registry URL is empty, original ECR URL should be returned
        self::assertEquals(
            self::ECR_URI,
            $image->getImageId(),
        );
    }
}
