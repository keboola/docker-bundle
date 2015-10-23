<?php

namespace Keboola\DockerBundle\Docker\Image\Builder;

use Keboola\DockerBundle\Exception\BuildException;

class BuilderParameter
{
    /**
     * Parameter name.
     * @var string
     */
    private $name;

    /**
     * Parameter type ('string', 'integer' ...).
     * @var string
     */
    private $type;

    /**
     * Arbitrary parameter value.
     * @var mixed
     */
    private $value;


    /**
     * Constructor
     * @param string $name Parameter name.
     * @param string $type Parameter type.
     */
    public function __construct($name, $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * Set value of the parameter
     * @param mixed $value
     */
    public function setValue($value)
    {
        switch ($this->type) {
            case 'integer':
                if (!is_scalar($value)) {
                    throw new BuildException("Invalid value $value for parameter " . $this->name);
                }
                $this->value = intval($value);
                break;
            case 'string':
                if (!is_scalar($value)) {
                    throw new BuildException("Invalid value $value for parameter " . $this->name);
                }
                $this->value = $value;
                break;
            case 'argument':
                if (!is_scalar($value)) {
                    throw new BuildException("Invalid value $value for parameter " . $this->name);
                }
                $this->value = escapeshellarg($value);
                break;
            default:
                throw new BuildException("Invalid type " . $this->type . " for parameter " . $this->name);
        }
    }


    /**
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }
}
