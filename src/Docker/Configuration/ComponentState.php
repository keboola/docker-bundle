<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Configuration;

class ComponentState
{
    /**
     * Mocked parsing method
     *
     * @param $configurations
     * @return object
     */
    public function parse($configurations)
    {
        if (!$configurations['config']) {
            return (object) [];
        }
        return $configurations['config'];
    }
}
