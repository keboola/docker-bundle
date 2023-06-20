<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker;

use Keboola\ObjectEncryptor\ObjectEncryptor;
use stdClass;

class JobScopedEncryptor
{
    private const PROTECTED_DEFAULT_BRANCH_FEATURE = 'protected-default-branch';

    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    public function __construct(
        private readonly ObjectEncryptor $encryptor,
        private readonly string $componentId,
        private readonly string $projectId,
        private readonly ?string $configId,
        private readonly string $branchType,
        private readonly array $projectFeatures,
    ) {
    }

    /**
     * @template T of array|stdClass|string
     * @param T $data
     * @return T
     */
    public function encrypt($data)
    {
        /* For SOX project, the cipher is created non transferable between branches. For normal projects, it is
        transferable. For both types of projects, the configId is ignored, because currently this method is
        used only to encrypt state. The question whether configId should be included in the state cipher or not
        is to be resolved https://keboola.atlassian.net/browse/PST-960 . */
        if (in_array(self::PROTECTED_DEFAULT_BRANCH_FEATURE, $this->projectFeatures, true)) {
            return $this->encryptor->encryptForBranchType(
                $data,
                $this->componentId,
                $this->projectId,
                $this->branchType,
            );
        }

        return $this->encryptor->encryptForProject(
            $data,
            $this->componentId,
            $this->projectId,
        );
    }

    /**
     * @template T of array|stdClass|string
     * @param T $data
     * @return T
     */
    public function decrypt($data)
    {
        /* no need to check for PROTECTED_DEFAULT_BRANCH_FEATURE because, decryptForBranchType and
            decryptForBranchTypeConfiguration can also decrypt ciphers without branchType. This is intentional behavior,
            covered by object encryptor:
            https://github.com/keboola/object-encryptor/blob/46555af72554a860fedf651198f520ff6e34bd31/tests/ObjectEncryptorTest.php#L1020
        */
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
