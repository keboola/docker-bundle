<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;

class ImageCreator
{
    private JobScopedEncryptor $jobScopedEncryptor;
    private LoggerInterface $logger;
    private Client $storageClient;
    private Component $mainComponent;
    private array $componentConfig;

    private array $before;
    private array $after;
    private Temp $temp;

    public function __construct(
        JobScopedEncryptor $jobQueueEncryptor,
        LoggerInterface $logger,
        Client $storageClient,
        Component $mainComponent,
        array $componentConfig
    ) {
        $this->jobScopedEncryptor = $jobQueueEncryptor;
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
            $image = ImageFactory::getImage($this->jobScopedEncryptor, $this->logger, $component, $this->temp, false);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        $image = ImageFactory::getImage($this->jobScopedEncryptor, $this->logger, $this->mainComponent, $this->temp, true);
        $image->prepare($this->componentConfig);
        $images[] = $image;

        foreach ($this->after as $processor) {
            $componentId = $processor['definition']['component'];
            $component = $this->getComponent($componentId);
            $image = ImageFactory::getImage($this->jobScopedEncryptor, $this->logger, $component, $this->temp, false);
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
