<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\Sandboxes\Api\Client as SandboxesApiClient;
use Keboola\Sandboxes\Api\Exception\ClientException;
use Keboola\Sandboxes\Api\Project;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Options\IndexOptions;
use Psr\Log\LoggerInterface;

class MlflowProjectResolver
{
    private const MLFLOW_FEATURE = 'sandboxes-python-mlflow';

    private StorageApiClient $storageApiClient;
    private SandboxesApiClient $sandboxesApiClient;
    private LoggerInterface $logger;

    public function __construct(
        StorageApiClient $storageApiClient,
        SandboxesApiClient $sandboxesApiClient,
        LoggerInterface $logger,
    ) {
        $this->storageApiClient = $storageApiClient;
        $this->sandboxesApiClient = $sandboxesApiClient;
        $this->logger = $logger;
    }

    public function getMlflowProjectIfAvailable(Component $component, array $tokenInfo): ?Project
    {
        if (!$component->allowMlflowArtifactsAccess()) {
            return null;
        }

        $mlflowFeaturePresent = in_array(self::MLFLOW_FEATURE, $tokenInfo['owner']['features'], true);
        if ($mlflowFeaturePresent === false) {
            $requestOptions = new IndexOptions();
            $requestOptions->setExclude(['components']);

            $stackFeatures = $this->storageApiClient->indexAction($requestOptions)['features'];
            $mlflowFeaturePresent = in_array(self::MLFLOW_FEATURE, $stackFeatures, true);
        }

        if ($mlflowFeaturePresent === false) {
            return null;
        }

        try {
            return $this->sandboxesApiClient->getProject();
        } catch (ClientException $e) {
            $this->logger->warning(
                'Failed to fetch MLflow project details. Access to MLflow artifacts will not be enabled!',
                [
                    'exception' => $e,
                ],
            );
            return null;
        }
    }
}
