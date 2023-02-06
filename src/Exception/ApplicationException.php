<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Exception;

use Exception;

class ApplicationException extends Exception
{
    protected $data = [];

    public function __construct($message, $previous = null, array $data = [])
    {
        parent::__construct($message, 0, $previous);
        $this->data = $data;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }
}
