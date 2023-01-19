<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;

abstract class ImageFactory
{
    const KNOWN_IMAGE_TYPES = ['dockerhub', 'builder', 'quayio', 'aws-ecr'];

    /**
     * @param bool $isMain True to mark the image as main image.
     * @return Image
     */
    public static function getImage(
        JobScopedEncryptor $jobScopedEncryptor,
        LoggerInterface $logger,
        Component $component,
        Temp $temp,
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
            case 'builder':
                $instance = new Image\Builder\ImageBuilder($jobScopedEncryptor, $component, $logger);
                $instance->setTemp($temp);
                break;
            default:
                throw new ApplicationException('Unknown image type: ' . $component->getType());
        }
        $instance->setIsMain($isMain);
        return $instance;
    }
}
