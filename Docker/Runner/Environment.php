<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;

class Environment
{
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

    /**
     * @var array
     */
    private $configParameters;

    /**
     * @var bool
     */
    private $injectEnvironment;

    /**
     * @var array
     */
    private $tokenInfo;

    /**
     * @var string
     */
    private $runId;

    /**
     * @var string
     */
    private $url;

    public function __construct($configId, array $component, array $configParameters, array $tokenInfo, $runId, $url)
    {
        $this->configId = $configId;
        $this->forwardToken = $component['forward_token'];
        $this->forwardTokenDetails = $component['forward_token_details'];
        $this->injectEnvironment = $component['inject_environment'];
        $this->configParameters = $configParameters;
        $this->tokenInfo = $tokenInfo;
        $this->runId = $runId;
        $this->url = $url;
    }

    public function getEnvironmentVariables()
    {
        if ($this->injectEnvironment) {
            $configParameters = $this->configParameters;
        } else {
            $configParameters = [];
        }
        $envs = $this->getConfigurationVariables($configParameters);
        // set environment variables
        $envs = array_merge($envs, [
            "KBC_RUNID" => $this->runId,
            "KBC_PROJECTID" => $this->tokenInfo["owner"]["id"],
            "KBC_DATADIR" => '/data/',
            "KBC_CONFIGID" => $this->configId,
        ]);
        if ($this->forwardToken) {
            $envs["KBC_TOKEN"] = $this->tokenInfo["token"];
            $envs["KBC_URL"] = $this->url;
        }
        if ($this->forwardTokenDetails) {
            $envs["KBC_PROJECTNAME"] = $this->tokenInfo["owner"]["name"];
            $envs["KBC_TOKENID"] = $this->tokenInfo["id"];
            $envs["KBC_TOKENDESC"] = $this->tokenInfo["description"];
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
