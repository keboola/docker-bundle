<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\StorageApi\Client;

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

    public function getEnvironmentVariables()
    {
        // @todo nebo predavat tokenfino aby se verifyToken nemuselo volat 2x?
        $tokenInfo = $this->storageClient->verifyToken();
        // set environment variables
        $envs = [
            "KBC_RUNID" => $this->storageClient->getRunId(),
            "KBC_PROJECTID" => $tokenInfo["owner"]["id"],
            "KBC_DATADIR" => '/data/',
            "KBC_CONFIGID" => $this->configId,
        ];
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
}
