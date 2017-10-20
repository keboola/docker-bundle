<?php

namespace Keboola\DockerBundle\Docker\Runner;

class Output
{
    /**
     * @var array
     */
    private $images = [];

    /**
     * @var string
     */
    private $output;

    /**
     * Output constructor.
     *
     * @param array $images
     * @param string|null $output
     */
    public function __construct(array $images = [], $output = null)
    {
        $this->images = $images;
        $this->output = $output;
    }

    /**
     * @return array
     */
    public function getImages()
    {
        return $this->images;
    }

    /**
     * @return string
     */
    public function getProcessOutput()
    {
        return $this->output;
    }
}
