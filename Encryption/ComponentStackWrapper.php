<?php

namespace Keboola\DockerBundle\Encryption;

use Keboola\Syrup\Encryption\BaseWrapper;

class ComponentStackWrapper extends JsonWrapper
{
    use StackDataTrait;
    use ComponentDataTrait;

    /**
     * ComponentStackWrapper constructor.
     *
     * TODO Maybe put also component here?
     *
     * @param $key
     * @param $stack
     * @param $stackKey
     */
    public function __construct($key, $stack, $stackKey)
    {
        parent::__construct($key);
        $this->setStack($stack);
        $this->setStackKey($stackKey);
    }

    /**
     * @param $encryptedData string
     * @return array decrypted data
     */
    public function decrypt($encryptedData)
    {
        $data = parent::decrypt($encryptedData);
        $this->validateComponent($data);
        $this->validateStack($data);
        $baseWrapper = new BaseWrapper($this->getStackKey());

        return $baseWrapper->decrypt($data["stacks"][$this->getStack()]);
    }

    /**
     * @param array $data
     * @return string
     */
    public function encrypt($data)
    {
        $baseWrapper = new BaseWrapper($this->getStackKey());
        $encryptedData = $baseWrapper->encrypt($data);
        $encapsulatedData = [
            "component" => $this->getComponent(),
            "stacks" => [
                $this->getStack() => $encryptedData
            ]
        ];

        return parent::encrypt($encapsulatedData);
    }

    /**
     * @param $data
     * @param $encrypted
     * @return string
     */
    public function add($data, $encrypted)
    {
        $decrypted = parent::decrypt($encrypted);
        $this->validateComponent($decrypted);
        $this->validateStackKey($decrypted);
        $baseWrapper = new BaseWrapper($this->getStackKey());
        $decrypted["stacks"][$this->getStack()] = $baseWrapper->encrypt($data);
        return parent::encrypt($decrypted);
    }

    /**
     * @inheritdoc
     */
    public function getPrefix()
    {
        return "KBC::ComponentStackEncrypted==";
    }
}
