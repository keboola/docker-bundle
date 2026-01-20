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

class GoogleArtifactRegistry extends Image
{
    private string $garRegistryUrl;
    private string $ecrRegistryUrl;

    public function __construct(
        ComponentSpecification $component,
        LoggerInterface $logger,
        string $garRegistryUrl,
        string $ecrRegistryUrl
    ) {
        parent::__construct($component, $logger);
        $this->garRegistryUrl = $garRegistryUrl;
        $this->ecrRegistryUrl = $ecrRegistryUrl;
    }

    /**
     * Get the transformed image ID with GAR registry URL instead of ECR
     */
    public function getImageId(): string
    {
        $originalImageId = parent::getImageId();
        if (!empty($this->ecrRegistryUrl) && !empty($this->garRegistryUrl)) {
            return str_replace($this->ecrRegistryUrl, $this->garRegistryUrl, $originalImageId);
        }
        return $originalImageId;
    }

    /**
     * Get login parameters for GAR using service account JSON key
     */
    public function getLoginParams(): string
    {
        $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if ($credentialsPath === false || $credentialsPath === '' || !file_exists($credentialsPath)) {
            throw new LoginFailedException(
                'GOOGLE_APPLICATION_CREDENTIALS environment variable is not set or file does not exist',
            );
        }

        $keyContent = file_get_contents($credentialsPath);
        if ($keyContent === false) {
            throw new LoginFailedException('Failed to read GCP service account key file');
        }

        $registryHost = $this->getRegistryHost();

        $loginParams = [];
        $loginParams[] = '--username=' . escapeshellarg('_json_key');
        $loginParams[] = '--password=' . escapeshellarg($keyContent);
        $loginParams[] = escapeshellarg($registryHost);
        return implode(' ', $loginParams);
    }

    /**
     * Get the registry host from the GAR URL
     */
    private function getRegistryHost(): string
    {
        $parts = explode('/', $this->garRegistryUrl);
        return $parts[0];
    }

    /**
     * Get logout parameters for GAR
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
