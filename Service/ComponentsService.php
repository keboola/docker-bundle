<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 */

namespace Keboola\DockerBundle\Service;

use Keboola\StorageApi\Components;

class ComponentsService
{
    /** @var Components */
    protected $components;

    /**
     * @param StorageApiService $service
     */
    public function __construct(\Keboola\Syrup\Service\StorageApi\StorageApiService $service)
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
