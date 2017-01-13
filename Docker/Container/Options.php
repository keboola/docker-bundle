<?php

namespace Keboola\DockerBundle\Docker\Container;

use Keboola\DockerBundle\Exception\ContainerOptionsException;

class Options
{
    /**
     * @var array
     */
    private $options;

    /**
     * @param array $options
     *
     * Sample:
     * [
     *      'name' => 'container-name'
     *      'label' => [
     *          'key1=val1',
     *          'key2=val2'
     *      ]
     * ]
     *
     */
    public function __construct(array $options)
    {
        foreach ($options as $option => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $this->setListOption($option, $item);
                }
            } else {
                $this->setScalarOption($option, $value);
            }
        }
    }

    /**
     * Gets options which accept only scalar values
     * e.g.: --name 'some-name'
     * @return array
     */
    public function getAllowedScalarOptions()
    {
        return [
            'hostname',
            'memory',
            'name',
        ];
    }

    /**
     * Gets options which accept list values
     * e.g.: --label 'key1=val1' --label 'key2=val2'
     * @return array
     */
    public function getAllowedListOptions()
    {
        return [
            'env',
            'label',
            'volume',
        ];
    }

    /**
     * Sets value for scalar option
     * @param $option
     * @param $value
     */
    public function setScalarOption($option, $value)
    {
        if (!$this->isScalarOption($option)) {
            throw new ContainerOptionsException('Specified option not exists or is not scalar type');
        }

        $this->options[$option] = $value;
    }

    /**
     * Sets value for list option.
     * @param $option
     * @param $value
     */
    public function setListOption($option, $value)
    {
        if (!$this->isListOption($option)) {
            throw new ContainerOptionsException('Specified option not exists or is not list type');
        }

        if (!isset($this->options[$option])) {
            $this->options[$option] = [];
        }

        array_push($this->options[$option], $value);
        $this->options[$option] = array_unique($this->options[$option]);
    }

    /**
     * Gets if option is set or not
     * @param $option
     * @return bool
     */
    public function isOptionSet($option)
    {
        return isset($this->options[$option]);
    }

    /**
     * Gets if option is scalar
     * @param $option
     * @return bool
     */
    public function isScalarOption($option)
    {
        return in_array($option, $this->getAllowedScalarOptions());
    }

    /**
     * Gets if option is list
     * @param $option
     * @return bool
     */
    public function isListOption($option)
    {
        return in_array($option, $this->getAllowedListOptions());
    }

    /**
     * Gets option ant its value(s) prepared as shell argument.
     * If option is not set empty string is returned
     * @param $option
     * @return string
     */
    public function getOptionAsShellArg($option)
    {
        if (!$this->isOptionSet($option)) {
            return '';
        }

        if ($this->isListOption($option)) {
            return implode(
                '',
                array_map(
                    [$this, 'createShellArgFromOptionAndValue'],
                    array_fill(0, count($this->options[$option]), $option),
                    $this->options[$option]
                )
            );
        } else {
            return $this->createShellArgFromOptionAndValue($option, $this->options[$option]);
        }
    }

    private function createShellArgFromOptionAndValue($option, $value)
    {
        return ' --' . $option . ' ' . escapeshellarg($value);
    }
}
