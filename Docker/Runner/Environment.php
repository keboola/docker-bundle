<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;

class Environment
{
    /**
     * @var Client
     */
    private $storageClient;

    /**
     * @var string
     */
    private $configId;

    /**
     * @var bool
     */
    private $forwardToken;

    /**
     * @var bool
     */
    private $forwardTokenDetails;

    public function __construct(Client $storageClient, $configId, $forwardToken, $forwardTokenDetails)
    {
        $this->storageClient = $storageClient;
        $this->configId = $configId;
        $this->forwardToken = $forwardToken;
        $this->forwardTokenDetails = $forwardTokenDetails;
    }

    public function getEnvironmentVariables($configurationVariables)
    {
        $envs = $this->getConfigurationVariables($configurationVariables);
        // @todo possibly pass tokenInfo so that verifyToken does not have to be called twice
        $tokenInfo = $this->storageClient->verifyToken();
        // set environment variables
        $envs = array_merge($envs, [
            "KBC_RUNID" => $this->storageClient->getRunId(),
            "KBC_PROJECTID" => $tokenInfo["owner"]["id"],
            "KBC_DATADIR" => '/data/',
            "KBC_CONFIGID" => $this->configId,
        ]);
        if ($this->forwardToken) {
            $envs["KBC_TOKEN"] = $tokenInfo["token"];
        }
        if ($this->forwardTokenDetails) {
            $envs["KBC_PROJECTNAME"] = $tokenInfo["owner"]["name"];
            $envs["KBC_TOKENID"] = $tokenInfo["id"];
            $envs["KBC_TOKENDESC"] = $tokenInfo["description"];
        }
        return $envs;
    }


    private function getConfigurationVariables($configurationVariables)
    {
        $envs = [];
        foreach ($configurationVariables as $name => $value) {
            if (is_scalar($value)) {
                $envs['KBC_PARAMETER_' . $this->sanitizeName($name)] = $value;
            } else {
                throw new UserException("Only scalar value is allowed as value for $name.");
            }
        }
        return $envs;
    }


    private function sanitizeName($name)
    {
        return strtoupper(preg_replace('@[^a-z]@', '_', strtolower($name)));
    }
}
