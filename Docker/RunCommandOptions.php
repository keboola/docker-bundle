<?php

namespace Keboola\DockerBundle\Docker;

class RunCommandOptions
{
    /**
     * @var array
     */
    private $labels;

    /**
     * @param array $labels
     */
    public function __construct(array $labels)
    {
        $this->labels = $labels;
    }

    /**
     * @return array
     */
    public function getLabels()
    {
        return $this->labels;
    }
}
