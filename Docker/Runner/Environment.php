<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\Syrup\Exception\UserException;

class Environment
{
    /**
     * @var string
     */
    private $configId;

    /**
     * @var array
     */
    private $configParameters;

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

    /**
     * @var Component
     */
    private $component;

    public function __construct($configId, Component $component, array $config, array $tokenInfo, $runId, $url)
    {
        if ($configId) {
            $this->configId = $configId;
        } else {
            $this->configId = sha1(serialize($config));
        }

        $this->component = $component;
        $this->configParameters = $config;
        $this->tokenInfo = $tokenInfo;
        $this->runId = $runId;
        $this->url = $url;
    }

    public function getEnvironmentVariables()
    {
        if ($this->component->injectEnvironment()) {
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
            "KBC_COMPONENTID" => $this->component->getId(),
        ]);
        if ($this->component->forwardToken()) {
            $envs["KBC_TOKEN"] = $this->tokenInfo["token"];
            $envs["KBC_URL"] = $this->url;
        }
        if ($this->component->forwardTokenDetails()) {
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
