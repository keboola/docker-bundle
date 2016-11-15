<?php

namespace Keboola\DockerBundle\Encryption;

use Keboola\DockerBundle\Exception\ComponentDataEncryptionException;

trait ComponentDataTrait
{

    /**
     * @var string
     */
    protected $component;

    /**
     * @return mixed
     */
    public function getComponent()
    {
        return $this->component;
    }

    /**
     * @param mixed $component
     * @return $this
     */
    public function setComponent($component)
    {
        $this->component = $component;

        return $this;
    }

    /**
     * @param $data
     */
    public function validateComponent($data)
    {
        if (!isset($data["component"]) || $data["component"] != $this->getComponent()) {
            throw new ComponentDataEncryptionException("Component mismatch.");
        }
    }
}
