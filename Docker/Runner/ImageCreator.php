<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\LoggerInterfaceTest;

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
            $this->logger->debug("Running processor $componentId");
            $component = $this->getComponent($componentId);
            $image = Image::factory($this->encryptor, $this->logger, $component, $this->temp, false);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        $image = Image::factory($this->encryptor, $this->logger, $this->mainComponent, $this->temp, true);
        $image->prepare($this->componentConfig);
        $images[] = $image;

        foreach ($this->after as $processor) {
            $componentId = $processor['definition']['component'];
            $this->logger->debug("Running processor $componentId");
            $component = $this->getComponent($componentId);
            $image = Image::factory($this->encryptor, $this->logger, $component, $this->temp, false);
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
        return new Component($component);
    }
}
