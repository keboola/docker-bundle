<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;

class ControllerHelper
{
    /**
     * @param Client $client Storage API client.
     * @param string $componentId Id of the component.
     * @return bool True if the component supports encryption.
     */
    public function hasComponentEncryptFlag(Client $client, $componentId)
    {
        $components = $client->indexAction();
        foreach ($components["components"] as $c) {
            if ($c["id"] == $componentId) {
                if (in_array('encrypt', $c['flags'])) {
                    return true;
                } else {
                    return false;
                }
            }
        }
        throw new UserException("Component $componentId not found.");
    }
}
