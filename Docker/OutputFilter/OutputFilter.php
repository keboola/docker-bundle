<?php

namespace Keboola\DockerBundle\Docker\OutputFilter;

class OutputFilter implements OutputFilterInterface
{
    const REPLACEMENT = '[hidden]';

    /**
     * @var array
     */
    private $filterValues = [];

    /**
     * @inheritdoc
     */
    public function addValue($value)
    {
        $this->filterValues[] = $value;
        // this is reversible, so hide it too
        $this->filterValues[] = base64_encode($value);
    }

    /**
     * @inheritdoc
     */
    public function collectValues(array $data)
    {
        array_walk_recursive($data, function ($value, $key) {
            if ((substr($key, 0, 1) == '#') && (is_scalar($value))) {
                $this->addValue($value);
            }
        });
    }

    /**
     * @inheritdoc
     */
    public function filter($text)
    {
        foreach ($this->filterValues as $filterValue) {
            $text = str_replace($filterValue, self::REPLACEMENT, $text);
        }
        return $text;
    }
}
