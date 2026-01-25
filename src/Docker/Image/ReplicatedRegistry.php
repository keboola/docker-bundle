<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

class ReplicatedRegistry extends Image
{
    private string $replicatedRegistryUrl;
    private string $sourceRegistryUrl;

    public function __construct(
        ComponentSpecification $component,
        LoggerInterface $logger,
        string $replicatedRegistryUrl,
        string $sourceRegistryUrl,
    ) {
        parent::__construct($component, $logger);
        $this->replicatedRegistryUrl = $replicatedRegistryUrl;
        $this->sourceRegistryUrl = $sourceRegistryUrl;
    }

    /**
     * Check if replicated registry is enabled via environment variables
     */
    public static function isEnabled(): bool
    {
        $useReplicatedRegistry = getenv('USE_REPLICATED_REGISTRY');
        $replicatedRegistryUrl = getenv('REPLICATED_REGISTRY_URL');

        return $useReplicatedRegistry === 'true'
            && $replicatedRegistryUrl !== false
            && $replicatedRegistryUrl !== '';
    }

    /**
     * Get the transformed image ID with replicated registry URL instead of source registry
     */
    public function getImageId(): string
    {
        $originalImageId = parent::getImageId();
        if (!empty($this->sourceRegistryUrl) && !empty($this->replicatedRegistryUrl)) {
            return str_replace($this->sourceRegistryUrl, $this->replicatedRegistryUrl, $originalImageId);
        }
        return $originalImageId;
    }

    /**
     * Get login parameters for the replicated registry
     */
    public function getLoginParams(): string
    {
        $login = getenv('REPLICATED_REGISTRY_LOGIN');
        $password = getenv('REPLICATED_REGISTRY_PASSWORD');

        if ($login === false || $login === '') {
            throw new LoginFailedException(
                'REPLICATED_REGISTRY_LOGIN environment variable is not set',
            );
        }

        if ($password === false || $password === '') {
            throw new LoginFailedException(
                'REPLICATED_REGISTRY_PASSWORD environment variable is not set',
            );
        }

        $registryHost = $this->getRegistryHost();

        $loginParams = [];
        $loginParams[] = '--username=' . escapeshellarg($login);
        $loginParams[] = '--password=' . escapeshellarg($password);
        $loginParams[] = escapeshellarg($registryHost);
        return implode(' ', $loginParams);
    }

    /**
     * Get the registry host from the replicated registry URL
     */
    private function getRegistryHost(): string
    {
        $parts = explode('/', $this->replicatedRegistryUrl);
        return $parts[0];
    }

    /**
     * Get logout parameters for the replicated registry
     */
    public function getLogoutParams(): string
    {
        $logoutParams = [];
        $logoutParams[] = escapeshellarg($this->getRegistryHost());
        return implode(' ', $logoutParams);
    }

    /**
     * Run docker login and docker pull in container
     */
    protected function pullImage(): void
    {
        $proxy = $this->getRetryProxy();

        $command = 'sudo docker login ' . $this->getLoginParams() . ' ' .
            '&& sudo docker pull ' . escapeshellarg($this->getFullImageId()) . ' ' .
            '&& sudo docker logout ' . $this->getLogoutParams();

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        try {
            $proxy->call(function () use ($process) {
                $process->mustRun();
            });
            $this->logImageHash();
        } catch (Throwable $e) {
            if (str_contains($process->getOutput(), '403 Forbidden')) {
                throw new LoginFailedException($process->getOutput());
            }
            throw new ApplicationException(
                sprintf(
                    "Cannot pull image '%s': (%s) %s %s",
                    $this->getPrintableImageId(),
                    $process->getExitCode(),
                    $process->getErrorOutput(),
                    $process->getOutput(),
                ),
                $e,
            );
        }
    }
}
