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
    private $before;

    /**
     * @var array
     */
    private $after;

    /**
     * @var array
     */
    private $componentConfig;

    public function __construct(
        ObjectEncryptor $encryptor,
        Logger $logger,
        Client $storageClient,
        array $mainImage,
        array $componentConfig
    ) {
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->mainImage = $mainImage;
        $this->storageClient = $storageClient;
        $this->before = empty($componentConfig['processors']['before']) ? [] : $componentConfig['processors']['before'];
        $this->after = empty($componentConfig['processors']['after']) ? [] : $componentConfig['processors']['after'];
        $this->componentConfig = $componentConfig;
    }

    /**
     * @return Image[]
     */
    public function prepareImages()
    {
        foreach ($this->before as $processor) {
            $componentId = $processor['definition']['component'];
            $this->logger->debug("Running processor $componentId");
            $component = $this->getComponent($componentId)['data'];
            $image = Image::factory($this->encryptor, $this->logger, $component, false);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        $image = Image::factory($this->encryptor, $this->logger, $this->mainImage, true);
        $image->prepare($this->componentConfig);
        $images[] = $image;

        foreach ($this->after as $processor) {
            $componentId = $processor['definition']['component'];
            $this->logger->debug("Running processor $componentId");
            $component = $this->getComponent($componentId)['data'];
            $image = Image::factory($this->encryptor, $this->logger, $component, false);
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
