<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\ObjectEncryptor\ObjectEncryptor;

class JobScopedEncryptor
{
    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    public function __construct(
        private readonly ObjectEncryptor $encryptor,
        private readonly string $componentId,
        private readonly string $projectId,
        private readonly ?string $configId,
        private readonly string $branchType,
    ) {
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public function encrypt($data)
    {
        if ($this->configId === null) {
            return $this->encryptor->encryptForBranchType(
                $data,
                $this->componentId,
                $this->projectId,
                $this->branchType,
            );
        }

        return $this->encryptor->encryptForBranchTypeConfiguration(
            $data,
            $this->componentId,
            $this->projectId,
            $this->configId,
            $this->branchType,
        );
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public function decrypt($data)
    {
        if ($this->configId === null) {
            return $this->encryptor->decryptForBranchType(
                $data,
                $this->componentId,
                $this->projectId,
                $this->branchType,
            );
        }

        return $this->encryptor->decryptForBranchTypeConfiguration(
            $data,
            $this->componentId,
            $this->projectId,
            $this->configId,
            $this->branchType,
        );
    }
}
