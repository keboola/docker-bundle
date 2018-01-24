<?php

namespace Keboola\DockerBundle\Encryption;

use Keboola\DockerBundle\Exception\StackDataEncryptionException;

trait StackDataTrait
{

    /**
     * @var string
     */
    protected $stack;

    /**
     * @var
     */
    protected $stackKey;

    /**
     * @return string
     */
    public function getStack()
    {
        return $this->stack;
    }

    /**
     * @param string $stack
     * @return $this
     */
    public function setStack($stack)
    {
        $this->stack = $stack;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getStackKey()
    {
        return $this->stackKey;
    }

    /**
     * @param mixed $stackKey
     * @return $this
     */
    public function setStackKey($stackKey)
    {
        $this->stackKey = $stackKey;

        return $this;
    }

    public function validateStackKey($data)
    {
        if (!isset($data["stacks"])) {
            throw new StackDataEncryptionException("Stacks not found.");
        }
    }

    public function validateStack($data)
    {
        $this->validateStackKey($data);
        if (!isset($data["stacks"][$this->getStack()])) {
            throw new StackDataEncryptionException("Stack {$this->getStack()} not found.");
        }
    }
}
