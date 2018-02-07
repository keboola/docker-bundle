<?php

namespace Keboola\DockerBundle\Docker;

class OutputFilter
{
    const REPLACEMENT = '*****';

    /**
     * @var array
     */
    private $filterValues = [];

    /**
     * OutputFilter constructor.
     * @param array $data Array of arrays containing sensitive values, values with keys marked with '#' are
     * considered sensitive.
     */
    public function __construct(array $data)
    {
        array_walk_recursive($data, function ($value, $key) {
            if ((substr($key, 0, 1) == '#') && (is_scalar($value))) {
                $this->filterValues[] = $value;
                // this is reversible, so hide it too
                $this->filterValues[] = base64_encode($value);
            }
        });
    }

    /**
     * @param string $text
     * @return string
     */
    public function filter($text) {
        foreach ($this->filterValues as $filterValue) {
            $text = str_replace($filterValue, self::REPLACEMENT, $text);
        }
        return $text;
    }
}
