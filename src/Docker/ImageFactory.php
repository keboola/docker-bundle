<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Psr\Log\LoggerInterface;

abstract class ImageFactory
{
    public const KNOWN_IMAGE_TYPES = ['dockerhub', 'quayio', 'aws-ecr'];
    private const ECR_REGISTRY_URL = '147946154733.dkr.ecr.us-east-1.amazonaws.com';

    /**
     * @param bool $isMain True to mark the image as main image.
     * @return Image
     */
    public static function getImage(
        LoggerInterface $logger,
        ComponentSpecification $component,
        $isMain,
    ) {
        $useGarRegistry = getenv('USE_GAR_REGISTRY') === 'true';
        $garRegistryUrl = getenv('GAR_REGISTRY_URL') ?: '';

        switch ($component->getType()) {
            case 'dockerhub':
                $instance = new Image\DockerHub($component, $logger);
                break;
            case 'quayio':
                $instance = new Image\QuayIO($component, $logger);
                break;
            case 'aws-ecr':
                if ($useGarRegistry && $garRegistryUrl !== '') {
                    $instance = new Image\GoogleArtifactRegistry(
                        $component,
                        $logger,
                        $garRegistryUrl,
                        self::ECR_REGISTRY_URL,
                    );
                } else {
                    $instance = new Image\AWSElasticContainerRegistry($component, $logger);
                }
                break;
            default:
                throw new ApplicationException('Unknown image type: ' . $component->getType());
        }
        $instance->setIsMain($isMain);
        return $instance;
    }
}
