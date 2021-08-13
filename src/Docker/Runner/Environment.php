<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;

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

    /**
     * @var string
     */
    private $configRowId;

    /**
     * @var string
     */
    private $token;

    /**
     * @var string
     */
    private $branchId;

    public function __construct($configId, $configRowId, Component $component, array $config, array $tokenInfo, $runId, $url, $token, $branchId)
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
        $this->configRowId = $configRowId;
        $this->branchId = $branchId;
    }

    public function getEnvironmentVariables(OutputFilterInterface $outputFilter)
    {
        // set environment variables
        $envs = [
            'KBC_RUNID' => $this->runId,
            'KBC_PROJECTID' => $this->tokenInfo['owner']['id'],
            'KBC_DATADIR' => '/data/',
            'KBC_CONFIGID' => $this->configId,
            'KBC_COMPONENTID' => $this->component->getId(),
            'KBC_STACKID' => $this->stackId,
            'KBC_STAGING_FILE_PROVIDER' => $this->tokenInfo['owner']['fileStorageProvider'],
        ];
        if ($this->configRowId) {
            $envs['KBC_CONFIGROWID'] = $this->configRowId;
        }
        if ($this->component->forwardToken()) {
            $envs['KBC_TOKEN'] = $this->token;
            $outputFilter->addValue($this->token);
            $envs['KBC_URL'] = $this->url;
        }
        if ($this->component->forwardTokenDetails()) {
            $envs['KBC_PROJECTNAME'] = $this->tokenInfo['owner']['name'];
            $envs['KBC_TOKENID'] = $this->tokenInfo['id'];
            $envs['KBC_TOKENDESC'] = $this->tokenInfo['description'];
            if (!empty($this->tokenInfo['admin']['samlParameters']['userId'])) {
                $envs['KBC_REALUSER'] = $this->tokenInfo['admin']['samlParameters']['userId'];
            }
        }
        if ($this->branchId) {
            $envs['KBC_BRANCHID'] = (string) $this->branchId;
        }
        return $envs;
    }
}
