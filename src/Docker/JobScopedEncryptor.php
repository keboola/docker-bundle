<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\ObjectEncryptor\ObjectEncryptor;

class JobScopedEncryptor
{
    private ObjectEncryptor $encryptor;
    private string $componentId;
    private string $projectId;
    private ?string $configId;

    public function __construct(ObjectEncryptor $encryptor, string $componentId, string $projectId, ?string $configId)
    {
        $this->encryptor = $encryptor;
        $this->componentId = $componentId;
        $this->projectId = $projectId;
        $this->configId = $configId;
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public function encrypt($data)
    {
        if ($this->configId === null) {
            return $this->encryptor->encryptForProject($data, $this->componentId, $this->projectId);
        }

        return $this->encryptor->encryptForConfiguration($data, $this->componentId, $this->projectId, $this->configId);
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public function decrypt($data)
    {
        if ($this->configId === null) {
            return $this->encryptor->decryptForProject($data, $this->componentId, $this->projectId);
        }

        return $this->encryptor->decryptForConfiguration($data, $this->componentId, $this->projectId, $this->configId);
    }
}
