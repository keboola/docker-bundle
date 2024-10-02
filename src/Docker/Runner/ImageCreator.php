<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Psr\Log\LoggerInterface;

class ImageCreator
{
    private array $before;
    private array $after;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly BranchAwareClient $storageClient,
        private readonly Component $mainComponent,
        private readonly array $componentConfig,
    ) {
        $this->before = $componentConfig['processors']['before'] ?: [];
        $this->after = $componentConfig['processors']['after'] ?: [];
    }

    /**
     * @return Image[]
     */
    public function prepareImages(): array
    {
        foreach ($this->before as $processor) {
            $images[] = $this->prepareImageForProcessor($processor);
        }

        $image = ImageFactory::getImage($this->logger, $this->mainComponent, true);
        $image->prepare($this->componentConfig);
        $images[] = $image;

        foreach ($this->after as $processor) {
            $images[] = $this->prepareImageForProcessor($processor);
        }

        return $images;
    }

    private function getComponent(string $id): Component
    {
        $componentsApi = new Components($this->storageClient);
        try {
            $component = $componentsApi->getComponent($id);
            return new Component($component);
        } catch (ClientException $e) {
            throw new UserException(sprintf('Cannot get component "%s": %s.', $id, $e->getMessage()), $e);
        }
    }

    private function prepareImageForProcessor(array $processorData): Image
    {
        $componentId = $processorData['definition']['component'];
        $component = $this->getComponent((string) $componentId);
        $image = ImageFactory::getImage($this->logger, $component, false);
        $image->prepare(['parameters' => $processorData['parameters'] ?: []]);
        return $image;
    }
}
