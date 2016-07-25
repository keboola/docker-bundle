<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Image;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;
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
        Client $storageClient,
        array $mainImage,
        array $processors,
        array $componentConfig
    ) {
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->mainImage = $mainImage;
        $this->processors = $processors;
        $this->storageClient = $storageClient;
        $this->processors['before'] = empty($this->processors['before']) ? [] : $this->processors['before'];
        $this->processors['after'] = empty($this->processors['after']) ? [] : $this->processors['after'];
        $this->componentConfig = $componentConfig;
    }

    /**
     * @return Image[]
     */
    public function prepareImages()
    {
        foreach ($this->processors['before'] as $processor) {
            $componentId = $processor['definition']['component'];
            $this->logger->debug("Running processor $componentId");
            $component = $this->getComponent($componentId)['data'];
            $image = Image::factory($this->encryptor, $this->logger, $component);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        $image = Image::factory($this->encryptor, $this->logger, $this->mainImage);
        $image->prepare($this->componentConfig);
        $images[] = $image;

        foreach ($this->processors['after'] as $processor) {
            $componentId = $processor['definition']['component'];
            $this->logger->debug("Running processor $componentId");
            $component = $this->getComponent($componentId)['data'];
            $image = Image::factory($this->encryptor, $this->logger, $component);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        return $images;
    }

    /**
     * @param $id
     */
    protected function getComponent($id)
    {
        // Check list of components
        $components = $this->storageClient->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $id) {
                $component = $c;
            }
        }
        if (!isset($component)) {
            throw new UserException("Component '{$id}' not found.");
        }
        return $component;
    }
}
