<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Service\ObjectEncryptor;
use Monolog\Logger;

class ImageCreator
{
    /**
     * @var ObjectEncryptor
     */
    private $encryptor;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array
     */
    private $mainImage;

    /**
     * @var array
     */
    private $processors;

    /**
     * @var array
     */
    private $componentConfig;

    public function __construct(
        ObjectEncryptor $encryptor,
        Logger $logger,
        array $mainImage,
        array $processors,
        array $componentConfig
    ) {
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->mainImage = $mainImage;
        $this->processors = $processors;
        $this->componentConfig = $componentConfig;
    }

    /**
     * @return Image[]
     */
    public function prepareImages()
    {
        foreach ($this->processors['before'] as $processor) {
            $images[] = Image::factory($this->encryptor, $this->logger, $processor);
        }
        $images[] = Image::factory($this->encryptor, $this->logger, $this->mainImage);
        foreach ($this->processors['after'] as $processor) {
            //$priority = $processor['priority'];
            $images[] = Image::factory($this->encryptor, $this->logger, $processor);
        }
        //ksort($images, SORT_ASC);

        /** @var Image[] $images */
        foreach ($images as $image) {
            $image->prepare($this->componentConfig);
        }
        return $images;
    }
}
