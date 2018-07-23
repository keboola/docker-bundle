<?php

namespace Keboola\DockerBundle\Exception;

class ApplicationException extends \Exception
{
    protected $data = array();

    public function __construct($message, $previous = null, array $data = [])
    {
        parent::__construct('Application error: ' . $message, 0, $previous);
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
