<?php
/**
 * Created by Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Job\Metadata;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;

/**
 * Class Job, added functionality
 * @package Keboola\DockerBundle\Job\Metadata
 */
class Job extends \Keboola\Syrup\Job\Metadata\Job
{

    /**
     * @var Client
     */
    protected $storageClient;

    /**
     * @param ObjectEncryptor $encryptor
     * @param Client $sapiClient
     * @param array $data
     * @param null $index
     * @param null $type
     * @param null $version
     */
    public function __construct(ObjectEncryptor $encryptor, Client $sapiClient, array $data = [], $index = null, $type = null, $version = null)
    {
        $this->setStorageClient($sapiClient);
        parent::__construct($encryptor, $data, $index, $type, $version);
    }

    /**
     * @return Client
     */
    public function getStorageClient()
    {
        return $this->storageClient;
    }

    /**
     * @param Client $storageClient
     * @return $this
     */
    public function setStorageClient($storageClient)
    {
        $this->storageClient = $storageClient;

        return $this;
    }

    /**
     *
     * return componentId
     *
     * @return mixed
     */
    public function getComponentId()
    {
        if (isset($this->getProperty('params')["component"])) {
            return $this->getProperty('params')["component"];
        }
        return false;
    }

    /**
     *
     * Do not decrypt /sandbox job
     * Do not decrypt jobs where component specifically not set with encrypt flag
     *
     * @return string
     */
    public function getParams()
    {
        $params = $this->getProperty('params');
        if (isset($params["mode"]) && $params["mode"] == "sandbox") {
            return $params;
        }

        $component = $this->getComponentConfiguration($params["component"]);
        if (in_array("encrypt", $component["flags"])) {
            return $this->getEncryptor()->decrypt($this->getProperty('params'));
        }

        return $params;
    }

    /**
     * @param $id
     * @return mixed
     * @throws UserException
     */
    protected function getComponentConfiguration($id)
    {
        // Check list of components
        $components = $this->getStorageClient()->indexAction();
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
