<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Image\ReplicatedRegistry;
use Keboola\DockerBundle\Docker\Image\ReplicatedRegistryImage;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReplicatedRegistryImageTest extends TestCase
{
    private const ECR_URI = '147946154733.dkr.ecr.us-east-1.amazonaws.com/keboola/test-component';
    private const DEFINITION_NAME = 'keboola/test-component';
    private const REPLICATED_REGISTRY_URL = 'us-docker.pkg.dev/my-project/my-repo';

    public static function provideImageIdTransformationData(): iterable
    {
        yield 'composes GAR URL from definition.name' => [
            'replicatedRegistryUrl' => self::REPLICATED_REGISTRY_URL,
            'expectedImageId' => 'us-docker.pkg.dev/my-project/my-repo/keboola/test-component',
        ];

        yield 'composes ACR URL from definition.name' => [
            'replicatedRegistryUrl' => 'myregistry.azurecr.io',
            'expectedImageId' => 'myregistry.azurecr.io/keboola/test-component',
        ];
    }

    /** @dataProvider provideImageIdTransformationData */
    public function testGetImageIdTransformation(
        string $replicatedRegistryUrl,
        string $expectedImageId,
    ): void {
        $imageConfig = new ComponentSpecification([
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => self::ECR_URI,
                    'name' => self::DEFINITION_NAME,
                ],
            ],
        ]);

        $service = new ReplicatedRegistry(
            true,
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
                    'name' => self::DEFINITION_NAME,
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

    public function testGetImageIdThrowsWhenDefinitionNameMissing(): void
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

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('definition.name required for replicated registry');

        $image->getImageId();
    }
}
