<?php
namespace Keboola\DockerBundle\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;

class State
{
    /**
     *
     * Mocked parsing method
     *
     * @param $configurations
     * @return array
     */
    public function parse($configurations)
    {
        if (!$configurations["config"]) {
            return (object) array();
        }
        return $configurations["config"];
    }
}
