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
     * Is the parameter required (both in dockerfile and in configuration)
     * @var bool
     */
    private $required;

    /**
     * Arbitrary parameter value.
     * @var mixed
     */
    private $value = null;


    /**
     * Constructor
     * @param string $name Parameter name.
     * @param string $type Parameter type.
     * @param bool $required
     */
    public function __construct($name, $type, $required)
    {
        $this->name = $name;
        $this->type = $type;
        $this->required = $required;
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
     * Get value.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Get parameter name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Return true if parameter is required both in Dockerfile and in configdata.
     *
     * @return bool
     */
    public function isRequired()
    {
        return $this->required;
    }
}
