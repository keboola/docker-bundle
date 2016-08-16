<?php

namespace Keboola\DockerBundle\Docker\Configuration;

class State
{
    /**
     * Mocked parsing method
     *
     * @param $configurations
     * @return object
     */
    public function parse($configurations)
    {
        if (!$configurations["config"]) {
            return (object) [];
        }
        return $configurations["config"];
    }
}
