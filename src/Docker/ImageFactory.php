<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Psr\Log\LoggerInterface;

class ImageFactory
{
    public const KNOWN_IMAGE_TYPES = ['dockerhub', 'quayio', 'aws-ecr'];
    private const ECR_REGISTRY_URL = '147946154733.dkr.ecr.us-east-1.amazonaws.com';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $isMain True to mark the image as main image.
     */
    public function getImage(
        ComponentSpecification $component,
        bool $isMain,
    ): Image {
        switch ($component->getType()) {
            case 'dockerhub':
                $instance = new Image\DockerHub($component, $this->logger);
                break;
            case 'quayio':
                $instance = new Image\QuayIO($component, $this->logger);
                break;
            case 'aws-ecr':
                if (Image\ReplicatedRegistry::isEnabled()) {
                    $replicatedRegistryUrl = getenv('REPLICATED_REGISTRY_URL');
                    $instance = new Image\ReplicatedRegistry(
                        $component,
                        $this->logger,
                        $replicatedRegistryUrl !== false ? $replicatedRegistryUrl : '',
                        self::ECR_REGISTRY_URL,
                    );
                } else {
                    $instance = new Image\AWSElasticContainerRegistry($component, $this->logger);
                }
                break;
            default:
                throw new ApplicationException('Unknown image type: ' . $component->getType());
        }
        $instance->setIsMain($isMain);
        return $instance;
    }
}
