<?php

namespace Keboola\DockerBundle\Docker\OutputFilter;

interface OutputFilterInterface
{
    /**
     * Add a single sensitive value
     * @param string $value
     */
    public function addValue($value);

    /**
     * Collect sensitive values
     * @param array $data Array of arrays containing sensitive values, values with keys marked with '#' are
     * considered sensitive.
     */
    public function collectValues(array $data);

    /**
     * @param string $text
     * @return string
     */
    public function filter($text);
}
