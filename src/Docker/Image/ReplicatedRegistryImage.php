<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Image;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\LoginFailedException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

class ReplicatedRegistryImage extends Image
{
    public function __construct(
        ComponentSpecification $component,
        LoggerInterface $logger,
        private readonly ReplicatedRegistry $replicatedRegistry,
    ) {
        parent::__construct($component, $logger);
    }

    public function getImageId(): string
    {
        $definitionName = $this->getSourceComponent()->getImageName();
        if ($definitionName === null) {
            throw new LogicException(sprintf(
                'Component "%s" is missing definition.name required for replicated registry.',
                $this->getSourceComponent()->getId(),
            ));
        }
        return $this->replicatedRegistry->composeImageUrl($definitionName);
    }

    protected function pullImage(): void
    {
        $proxy = $this->getRetryProxy();

        $command = 'sudo docker login ' . $this->replicatedRegistry->getLoginParams() . ' ' .
            '&& sudo docker pull ' . escapeshellarg($this->getFullImageId()) . ' ' .
            '&& sudo docker logout ' . $this->replicatedRegistry->getLogoutParams();

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
