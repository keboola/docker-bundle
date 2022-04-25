<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;

class MlflowTracking
{
    private string $uri;
    private ?string $token;

    public function __construct(string $url, ?string $token = null)
    {
        $this->uri = $url;
        $this->token = $token;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function exportAsEnv(OutputFilterInterface $outputFilter): array
    {
        $env = [
            'MLFLOW_TRACKING_URI' => $this->uri,
        ];

        if ($this->token !== null) {
            $env['MLFLOW_TRACKING_TOKEN'] = $this->token;
            $outputFilter->addValue($this->token);
        }

        return $env;
    }
}
