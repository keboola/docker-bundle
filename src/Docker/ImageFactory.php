<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ApplicationException;
use Psr\Log\LoggerInterface;

abstract class ImageFactory
{
    const KNOWN_IMAGE_TYPES = ['dockerhub', 'quayio', 'aws-ecr'];

    /**
     * @param bool $isMain True to mark the image as main image.
     * @return Image
     */
    public static function getImage(
        LoggerInterface $logger,
        Component $component,
        $isMain
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
