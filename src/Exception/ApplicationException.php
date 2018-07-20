<?php

namespace Keboola\DockerBundle\Exception;

class ApplicationException extends \Keboola\Syrup\Exception\ApplicationException
{
    protected $data = array();

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
