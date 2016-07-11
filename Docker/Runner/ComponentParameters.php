<?php

namespace Keboola\DockerBundle\Docker\Runner;

// image parameters which are supposed to be passed into the container
use Keboola\Syrup\Service\ObjectEncryptor;

class ComponentParameters
{
    /**
     * @var ObjectEncryptor
     */
    private $encryptor;

    /**
     * @var array
     */
    private $componentParameters;

    /**
     * @var bool
     */
    private $sandboxed;

    public function __construct(ObjectEncryptor $encryptor, array $componentParameters, $sandboxed)
    {
        $this->encryptor = $encryptor;
        $this->componentParameters = $componentParameters;
        $this->sandboxed = $sandboxed;
    }

    public function getComponentParameters()
    {
        if ($this->sandboxed) {
            // do not decrypt image parameters on sandboxed calls
            $imageParameters = $this->componentParameters;
        } else {
            $imageParameters = $this->encryptor->decrypt($this->componentParameters);
        }
        return $imageParameters;
    }
}
