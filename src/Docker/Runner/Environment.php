<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
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

    /**
     * @var string
     */
    private $stackId;

    public function __construct($configId, Component $component, array $config, array $tokenInfo, $runId, $url, $token)
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
        $this->stackId = parse_url($url, PHP_URL_HOST);
        $this->token = $token;
    }

    public function getEnvironmentVariables(OutputFilterInterface $outputFilter)
    {
        // set environment variables
        $envs = [
            "KBC_RUNID" => $this->runId,
            "KBC_PROJECTID" => $this->tokenInfo["owner"]["id"],
            "KBC_DATADIR" => '/data/',
            "KBC_CONFIGID" => $this->configId,
            "KBC_COMPONENTID" => $this->component->getId(),
            "KBC_STACKID" => $this->stackId
        ];
        if ($this->component->forwardToken()) {
            $envs["KBC_TOKEN"] = $this->token;
            $outputFilter->addValue($this->token);
            $envs["KBC_URL"] = $this->url;
        }
        if ($this->component->forwardTokenDetails()) {
            $envs["KBC_PROJECTNAME"] = $this->tokenInfo["owner"]["name"];
            $envs["KBC_TOKENID"] = $this->tokenInfo["id"];
            $envs["KBC_TOKENDESC"] = $this->tokenInfo["description"];
        }
        return $envs;
    }
}
