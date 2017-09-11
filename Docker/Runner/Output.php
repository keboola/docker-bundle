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
     * Add image digests
     * @param int $priority
     * @param string $imageId
     * @param array $imageDigests
     */
    public function addImages($priority, $imageId, $imageDigests)
    {
        $this->images[$priority] = ['id' => $imageId, 'digests' => $imageDigests];
    }

    /**
     * Add output of process
     * @param $output
     */
    public function addProcessOutput($output)
    {
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
