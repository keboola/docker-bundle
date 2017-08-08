<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;

abstract class ImageFactory
{
    const KNOWN_IMAGE_TYPES = ['dockerhub', 'dockerhub-private', 'builder', 'quayio', 'quayio-private', 'aws-ecr', 'legacy'];

    /**
     * @param ObjectEncryptor $encryptor Encryptor for image definition.
     * @param LoggerInterface $logger Logger instance.
     * @param Component $component Docker image runtime configuration.
     * @param Temp $temp Temporary service.
     * @param bool $isMain True to mark the image as main image.
     * @return Image|DockerHub
     */
    public static function getImage(ObjectEncryptor $encryptor, LoggerInterface $logger, Component $component, Temp $temp, $isMain)
    {
        switch ($component->getType()) {
            case "dockerhub":
                $instance = new Image\DockerHub($encryptor, $component, $logger);
                break;
            case "quayio":
                $instance = new Image\QuayIO($encryptor, $component, $logger);
                break;
            case "dockerhub-private":
                $instance = new Image\DockerHub\PrivateRepository($encryptor, $component, $logger);
                break;
            case "quayio-private":
                $instance = new Image\QuayIO\PrivateRepository($encryptor, $component, $logger);
                break;
            case "aws-ecr":
                $instance = new Image\AWSElasticContainerRegistry($encryptor, $component, $logger);
                break;
            case "builder":
                $instance = new Image\Builder\ImageBuilder($encryptor, $component, $logger);
                $instance->setTemp($temp);
                break;
            default:
                throw new ApplicationException("Unknown image type: " . $component->getType());
        }
        $instance->setIsMain($isMain);
        return $instance;
    }
}
