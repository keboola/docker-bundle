<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Image;

use Aws\Credentials\CredentialProvider;
use Aws\Ecr\EcrClient;
use Aws\Result;
use Aws\Sts\StsClient;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

class AWSElasticContainerRegistry extends Image
{
    private string $awsRegion = 'us-east-1';
    private const CONNECT_TIMEOUT = 10;
    private const CONNECT_RETRIES = 0;
    private const TRANSFER_TIMEOUT = 120;

    public function __construct(Component $component, LoggerInterface $logger)
    {
        parent::__construct($component, $logger);
        if (!empty($component->getImageDefinition()['repository']['region'])) {
            $this->awsRegion = $component->getImageDefinition()['repository']['region'];
        }
    }

    public function getAwsRegion(): string
    {
        return $this->awsRegion;
    }

    public function getAwsAccountId(): string
    {
        if (!str_contains($this->getImageId(), '.')) {
            throw new ApplicationException(
                sprintf(
                    'Invalid image ID format: "%s".',
                    $this->getImageId(),
                ),
            );
        }
        return substr($this->getImageId(), 0, strpos($this->getImageId(), '.'));
    }

    public function getLoginParams(): string
    {
        $stsClient = new StsClient([
            'region' => $this->getAwsRegion(),
            'version' => '2011-06-15',
            'retries' => self::CONNECT_RETRIES,
            'http' => [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TRANSFER_TIMEOUT,
            ],
            'credentials' => false,
        ]);
        $awsCredentials = CredentialProvider::defaultProvider([
            'region' => $this->getAwsRegion(),
            'stsClient' => $stsClient,
        ]);
        $ecrClient = new EcrClient([
            'region' => $this->getAwsRegion(),
            'version' => '2015-09-21',
            'retries' => self::CONNECT_RETRIES,
            'http' => [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TRANSFER_TIMEOUT,
            ],
            'credentials' => $awsCredentials,
        ]);
        /** @var Result $authorization */
        $authorization = null;
        $proxy = $this->getRetryProxy();
        try {
            $proxy->call(function () use ($ecrClient, &$authorization) {
                try {
                    $authorization = $ecrClient->getAuthorizationToken(['registryIds' => [$this->getAwsAccountId()]]);
                    // \Exception because "Before PHP 7, Exception did not implement the Throwable interface."
                    // https://www.php.net/manual/en/class.exception.php
                } catch (Throwable $e) {
                    $this->logger->notice('Retrying AWS GetCredentials. error: ' . $e->getMessage());
                    throw $e;
                }
            });
        } catch (Throwable $e) {
            throw new LoginFailedException($e->getMessage(), $e);
        }
        // decode token and extract user
        list($user, $token) =
            explode(':', base64_decode($authorization->get('authorizationData')[0]['authorizationToken']));

        $loginParams[] = '--username=' . escapeshellarg($user);
        $loginParams[] = '--password=' . escapeshellarg($token);
        $loginParams[] = escapeshellarg($authorization->get('authorizationData')[0]['proxyEndpoint']);
        return join(' ', $loginParams);
    }

    /**
     * @return string
     */
    public function getLogoutParams()
    {
        $logoutParams = [];
        $logoutParams[] = escapeshellarg($this->getImageId());
        return join(' ', $logoutParams);
    }

    /**
     * Run docker login and docker pull in container, login/logout race conditions
     */
    protected function pullImage(): void
    {
        $proxy = $this->getRetryProxy();

        $command = 'sudo docker login ' . $this->getLoginParams() .  ' ' .
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
