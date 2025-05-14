<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner\DataLoader;

use InvalidArgumentException;
use Keboola\StagingProvider\Provider\Configuration\WorkspaceCredentials;

readonly class ExternallyManagedWorkspaceCredentials
{
    public const TYPE_SNOWFLAKE = 'snowflake';

    public const VALID_TYPES = [
        self::TYPE_SNOWFLAKE,
    ];

    public function __construct(
        /** @var non-empty-string */
        public string $id,
        /** @var value-of<self::VALID_TYPES> */
        public string $type,
        public ?string $password,
        public ?string $privateKey,
    ) {
        if (count(array_filter([$this->password, $this->privateKey])) !== 1) {
            throw new InvalidArgumentException(
                'Exactly one of "privateKey" and "password" must be configured workspace_credentials',
            );
        }
    }

    /**
     * @param array{
     *   id: non-empty-string,
     *   type: value-of<self::VALID_TYPES>,
     *   "#password"?: string|null,
     *   "#privateKey"?: string|null,
     * } $credentials
     */
    public static function fromArray(array $credentials): self
    {
        return new self(
            (string) $credentials['id'],
            $credentials['type'],
            isset($credentials['#password']) ? ((string) $credentials['#password']) : null,
            isset($credentials['#privateKey']) ? ((string) $credentials['#privateKey']) : null,
        );
    }

    public function getWorkspaceCredentials(): WorkspaceCredentials
    {
        return new WorkspaceCredentials([
            'password' => $this->password,
            'privateKey' => $this->privateKey,
        ]);
    }
}
