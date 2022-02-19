<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;

class ImageCreator
{
    /**
     * @var ObjectEncryptor
     */
    private $encryptor;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Component
     */
    private $mainComponent;

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

    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var Client
     */
    private $storageClient;

    /**
     * ImageCreator constructor.
     * @param ObjectEncryptor $encryptor
     * @param LoggerInterface $logger
     * @param Client $storageClient
     * @param Component $mainComponent
     * @param array $componentConfig
     */
    public function __construct(
        ObjectEncryptor $encryptor,
        LoggerInterface $logger,
        Client $storageClient,
        Component $mainComponent,
        array $componentConfig
    ) {
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->mainComponent = $mainComponent;
        $this->storageClient = $storageClient;
        $this->before = empty($componentConfig['processors']['before']) ? [] : $componentConfig['processors']['before'];
        $this->after = empty($componentConfig['processors']['after']) ? [] : $componentConfig['processors']['after'];
        $this->componentConfig = $componentConfig;
        $this->temp = new Temp();
        $this->temp->initRunFolder();
    }

    /**
     * @return Image[]
     */
    public function prepareImages()
    {
        foreach ($this->before as $processor) {
            $componentId = $processor['definition']['component'];
            $component = $this->getComponent($componentId);
            $image = ImageFactory::getImage($this->encryptor, $this->logger, $component, $this->temp, false);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        $image = ImageFactory::getImage($this->encryptor, $this->logger, $this->mainComponent, $this->temp, true);
        $image->prepare($this->componentConfig);
        $images[] = $image;

        foreach ($this->after as $processor) {
            $componentId = $processor['definition']['component'];
            $component = $this->getComponent($componentId);
            $image = ImageFactory::getImage($this->encryptor, $this->logger, $component, $this->temp, false);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        return $images;
    }

    /**
     * @param string $id
     * @return Component
     */
    protected function getComponent($id)
    {
        $componentsApi = new Components($this->storageClient);
        try {
            $component = $componentsApi->getComponent($id);
            return new Component($component);
        } catch (ClientException $e) {
            throw new UserException(sprintf('Cannot get component "%s": %s.', $id, $e->getMessage()), $e);
        }
    }
}
