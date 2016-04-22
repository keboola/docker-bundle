<?php

namespace Keboola\DockerBundle\Controller;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;

class ControllerHelper
{
    /**
     * @param string $componentId Id of the component.
     * @return bool True if the component supports encryption.
     */
    public static function hasComponentEncryptFlag($componentId)
    {
        $storageApi = new Client(['token' => '']);
        $components = $storageApi->indexAction();
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
