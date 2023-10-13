<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\Temp\Temp;
use Psr\Log\LoggerInterface;

class ImageCreator
{
    private LoggerInterface $logger;
    private BranchAwareClient $storageClient;
    private Component $mainComponent;
    private array $componentConfig;

    private array $before;
    private array $after;
    private Temp $temp;

    public function __construct(
        LoggerInterface $logger,
        BranchAwareClient $storageClient,
        Component $mainComponent,
        array $componentConfig,
    ) {
        $this->logger = $logger;
        $this->mainComponent = $mainComponent;
        $this->storageClient = $storageClient;
        $this->before = empty($componentConfig['processors']['before']) ? [] : $componentConfig['processors']['before'];
        $this->after = empty($componentConfig['processors']['after']) ? [] : $componentConfig['processors']['after'];
        $this->componentConfig = $componentConfig;
        $this->temp = new Temp();
    }

    /**
     * @return Image[]
     */
    public function prepareImages()
    {
        foreach ($this->before as $processor) {
            $componentId = $processor['definition']['component'];
            $component = $this->getComponent($componentId);
            $image = ImageFactory::getImage($this->logger, $component, false);
            $image->prepare(['parameters' => empty($processor['parameters']) ? [] : $processor['parameters']]);
            $images[] = $image;
        }

        $image = ImageFactory::getImage($this->logger, $this->mainComponent, true);
        $image->prepare($this->componentConfig);
        $images[] = $image;

        foreach ($this->after as $processor) {
            $componentId = $processor['definition']['component'];
            $component = $this->getComponent($componentId);
            $image = ImageFactory::getImage($this->logger, $component, false);
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
