<?php

namespace Keboola\DockerBundle\Docker\Image\Builder;

use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;

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
     * Is the parameter required (both in dockerfile and in configuration).
     * @var bool
     */
    private $required;

    /**
     * Array of allowed parameter values (for enumeration parameters).
     * @var array
     */
    private $allowedValues;

    /**
     * Arbitrary parameter value.
     * @var mixed
     */
    private $value = null;

    /**
     * Predefined value of the parameter.
     * @var null
     */
    private $defaultValue = null;


    /**
     * Constructor
     * @param string $name Parameter name.
     * @param string $type Parameter type.
     * @param bool $required
     * @param string $defaultValue Initial parameter value.
     * @param array $values Allowed values of the parameter
     */
    public function __construct($name, $type, $required, $defaultValue = null, $values = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->required = $required;
        $this->defaultValue = $defaultValue;
        $this->value = $defaultValue;
        $this->allowedValues = $values;
        if (($this->type == 'enumeration') && (count($this->allowedValues) == 0)) {
            throw new BuildException("Enumeration $name contains no valid values.");
        }
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
                    throw new BuildParameterException(
                        "Invalid value " . var_export($value, true) . " for parameter " . $this->name .
                        ", it cannot be converted to number."
                    );
                }
                $this->value = intval($value);
                break;
            case 'string':
                if (!is_scalar($value)) {
                    throw new BuildParameterException(
                        "Invalid value " . var_export($value, true) . " for parameter " . $this->name .
                        ", it cannot be converted to text."
                    );
                }
                $this->value = $value;
                break;
            case 'argument':
                if (!is_scalar($value)) {
                    throw new BuildParameterException(
                        "Invalid value " . var_export($value, true) . " for parameter " . $this->name .
                        ", it cannot be converted to string."
                    );
                }
                $this->value = escapeshellarg($value);
                break;
            case 'plain_string':
                if (!is_scalar($value) || !preg_match('#^[a-z0-9_.-]+$#i', $value)) {
                    throw new BuildParameterException(
                        "Invalid value " . var_export($value, true) . " for parameter " . $this->name .
                        ", it must not contain special characters (a-z 0-9 _ . allowed)."
                    );
                }
                $this->value = $value;
                break;
            case 'enumeration':
                if (!is_scalar($value) || !in_array($value, $this->allowedValues)) {
                    throw new BuildParameterException(
                        "Invalid value " . var_export($value, true) . " for parameter " . $this->name .
                        ", it must be any of " . implode(", ", $this->allowedValues) . "."
                    );
                }
                $this->value = $value;
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
