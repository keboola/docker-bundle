<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Psr\Log\LoggerInterface;

abstract class ImageFactory
{
    public const KNOWN_IMAGE_TYPES = ['dockerhub', 'quayio', 'aws-ecr'];

    /**
     * @param bool $isMain True to mark the image as main image.
     * @return Image
     */
    public static function getImage(
        LoggerInterface $logger,
        ComponentSpecification $component,
        $isMain,
    ) {
        switch ($component->getType()) {
            case 'dockerhub':
                $instance = new Image\DockerHub($component, $logger);
                break;
            case 'quayio':
                $instance = new Image\QuayIO($component, $logger);
                break;
            case 'aws-ecr':
                $instance = new Image\AWSElasticContainerRegistry($component, $logger);
                break;
            default:
                throw new ApplicationException('Unknown image type: ' . $component->getType());
        }
        $instance->setIsMain($isMain);
        return $instance;
    }
}
