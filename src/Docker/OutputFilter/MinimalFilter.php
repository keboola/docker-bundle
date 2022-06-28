<?php

namespace Keboola\DockerBundle\Docker\OutputFilter;

use Keboola\DockerBundle\Docker\Container\WtfWarningFilter;

class MinimalFilter implements OutputFilterInterface
{
    public function addValue($value)
    {
    }

    public function collectValues(array $data)
    {
    }

    public function filter($text)
    {
        return WtfWarningFilter::filter($text);
    }
}
