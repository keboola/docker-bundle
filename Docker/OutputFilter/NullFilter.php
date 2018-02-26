<?php

namespace Keboola\DockerBundle\Docker\OutputFilter;

class NullFilter implements OutputFilterInterface
{
    /**
     * @inheritdoc
     */
    public function addValue($value)
    {
    }

    /**
     * @inheritdoc
     */
    public function collectValues(array $data)
    {
    }

    /**
     * @inheritdoc
     */
    public function filter($text)
    {
        return $text;
    }
}
