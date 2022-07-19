<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;

class Environment
{
    private ?string $configId;
    private array $tokenInfo;
    private ?string $runId;
    private string $url;
    private Component $component;
    private string $stackId;
    private ?string $configRowId;
    private string $token;
    private ?string $branchId;
    private ?string $absConnectionString;
    private ?MlflowTracking $mlflowTracking;

    public function __construct(
        ?string $configId,
        ?string $configRowId,
        Component $component,
        array $config,
        array $tokenInfo,
        ?string $runId,
        string $url,
        string $token,
        ?string $branchId,
        ?string $absConnectionString,
        ?MlflowTracking $mlflowTracking
    ) {
        if ($configId) {
            $this->configId = $configId;
        } else {
            $this->configId = sha1(serialize($config));
        }

        $this->component = $component;
        $this->tokenInfo = $tokenInfo;
        $this->runId = $runId;
        $this->url = $url;
        $this->stackId = parse_url($url, PHP_URL_HOST);
        $this->token = $token;
        $this->configRowId = $configRowId;
        $this->branchId = $branchId;
        $this->absConnectionString = $absConnectionString;
        $this->mlflowTracking = $mlflowTracking;
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

        if ($this->absConnectionString !== null) {
            $envs['AZURE_STORAGE_CONNECTION_STRING'] = $this->absConnectionString;
        }

        if ($this->mlflowTracking !== null) {
            $envs = array_merge($envs, $this->mlflowTracking->exportAsEnv($outputFilter));
        }

        return $envs;
    }
}
