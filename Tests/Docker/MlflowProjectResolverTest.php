<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\MlflowProjectResolver;
use Keboola\Sandboxes\Api\Client as SandboxesApiClient;
use Keboola\Sandboxes\Api\Exception\ClientException;
use Keboola\Sandboxes\Api\Project;
use Keboola\StorageApi\Client as StorageApiClient;
use PHPUnit\Framework\TestCase;

class MlflowProjectResolverTest extends TestCase
{
    private const PROJECT_MLFLOW_FEATURE = 'sandboxes-python-mlflow';
    private const STACK_MLFLOW_FEATURE = 'sandboxes-python-mlflow';
    private const COMPONENT_MLFLOW_FEATURE = 'mlflow-artifacts-access';

    /**
     * @dataProvider provideGetProjectDependingOnFeaturesTestData
     */
    public function testGetProjectDependingOnFeatures(
        array $componentFeatures,
        array $projectFeatures,
        array $stackFeatures,
        bool $returnsProject
    ): void {
        $storageApiClient = $this->createMock(StorageApiClient::class);
        $storageApiClient->method('indexAction')->willReturn([
            'features' => $stackFeatures,
        ]);

        $sandboxesApiClient = $this->createMock(SandboxesApiClient::class);
        $sandboxesApiClient->method('getProject')->willReturn(new Project());

        $component = new Component([
            'id' => 'keboola.runner-config-test',
            'features' => $componentFeatures,
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
            ],
        ]);

        $tokenInfo = [
            'owner' => [
                'features' => $projectFeatures,
            ]
        ];

        $resolver = new MlflowProjectResolver($storageApiClient, $sandboxesApiClient);
        $project = $resolver->getMlflowProjectIfAvailable($component, $tokenInfo);

        self::assertSame($returnsProject, $project !== null);
    }

    public function provideGetProjectDependingOnFeaturesTestData(): iterable
    {
        yield 'no feature' => [
            'componentFeatures' => [],
            'projectFeatures' => [],
            'stackFeatures' => [],
            'returnsProject' => false,
        ];

        yield 'only component feature' => [
            'componentFeatures' => [self::COMPONENT_MLFLOW_FEATURE],
            'projectFeatures' => [],
            'stackFeatures' => [],
            'returnsProject' => false,
        ];

        yield 'only project feature' => [
            'componentFeatures' => [],
            'projectFeatures' => [self::PROJECT_MLFLOW_FEATURE],
            'stackFeatures' => [],
            'returnsProject' => false,
        ];

        yield 'only stack feature' => [
            'componentFeatures' => [],
            'projectFeatures' => [],
            'stackFeatures' => [self::STACK_MLFLOW_FEATURE],
            'returnsProject' => false,
        ];

        yield 'only stack & project feature' => [
            'componentFeatures' => [],
            'projectFeatures' => [self::PROJECT_MLFLOW_FEATURE],
            'stackFeatures' => [self::STACK_MLFLOW_FEATURE],
            'returnsProject' => false,
        ];

        yield 'component + project feature' => [
            'componentFeatures' => [self::COMPONENT_MLFLOW_FEATURE],
            'projectFeatures' => [self::PROJECT_MLFLOW_FEATURE],
            'stackFeatures' => [],
            'returnsProject' => true,
        ];

        yield 'component + stack feature' => [
            'componentFeatures' => [self::COMPONENT_MLFLOW_FEATURE],
            'projectFeatures' => [],
            'stackFeatures' => [self::STACK_MLFLOW_FEATURE],
            'returnsProject' => true,
        ];

        yield 'component + stack & project feature' => [
            'componentFeatures' => [self::COMPONENT_MLFLOW_FEATURE],
            'projectFeatures' => [self::PROJECT_MLFLOW_FEATURE],
            'stackFeatures' => [self::STACK_MLFLOW_FEATURE],
            'returnsProject' => true,
        ];
    }

    public function testGetNotExistingProject(): void
    {
        $storageApiClient = $this->createMock(StorageApiClient::class);
        $storageApiClient->method('indexAction')->willReturn([
            'features' => [self::STACK_MLFLOW_FEATURE],
        ]);

        $sandboxesApiClient = $this->createMock(SandboxesApiClient::class);
        $sandboxesApiClient->method('getProject')->willThrowException(
            new ClientException('Project not found', 404)
        );

        $component = new Component([
            'id' => 'keboola.runner-config-test',
            'features' => [self::COMPONENT_MLFLOW_FEATURE],
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'keboola/docker-demo',
                    'tag' => 'master'
                ],
            ],
        ]);

        $tokenInfo = [
            'owner' => [
                'features' => [self::PROJECT_MLFLOW_FEATURE],
            ]
        ];

        $resolver = new MlflowProjectResolver($storageApiClient, $sandboxesApiClient);
        $project = $resolver->getMlflowProjectIfAvailable($component, $tokenInfo);

        self::assertNull($project);
    }
}
