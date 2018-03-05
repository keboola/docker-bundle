<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 */

namespace Keboola\DockerBundle\Service;

use Keboola\StorageApi\Components;
use Keboola\Syrup\Service\StorageApi\StorageApiService as OriginalStorageApiService;

class ComponentsService
{
    /** @var Components */
    protected $components;

    /**
     * @param OriginalStorageApiService $service
     */
    public function __construct(OriginalStorageApiService $service)
    {
        $this->components = new Components($service->getClient());
    }

    /**
     * @return Components
     */
    public function getComponents()
    {
        return $this->components;
    }
}
