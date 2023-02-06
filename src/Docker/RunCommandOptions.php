<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

class RunCommandOptions
{
    /**
     * @var array
     */
    private $labels;

    /**
     * @var array
     */
    private $environmentVariables;

    /**
     * @param array $labels
     * @param array $environmentVariables
     */
    public function __construct(array $labels, array $environmentVariables)
    {
        $this->labels = $labels;
        $this->environmentVariables = $environmentVariables;
    }

    /**
     * @return array
     */
    public function getLabels()
    {
        return $this->labels;
    }

    /**
     * @return array
     */
    public function getEnvironmentVariables()
    {
        return $this->environmentVariables;
    }

    /**
     * @param array $environmentVariables
     */
    public function setEnvironmentVariables(array $environmentVariables)
    {
        $this->environmentVariables = $environmentVariables;
    }
}
