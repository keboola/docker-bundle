<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\GitHubApp;

use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\ObjectEncryptor\Exception\ApplicationException as EncryptorApplicationException;
use Keboola\ObjectEncryptor\Exception\UserException as EncryptorUserException;

class GitHubAppAuthorization
{
    private const GIT_AUTH_GITHUB_APP = 'githubApp';

    /** @var array<string, array{'#token': string, expiresAt: string}> */
    private array $authorizations = [];

    public function __construct(
        private readonly ?GitHubAppTokenClient $tokenClient,
        private readonly JobScopedEncryptor $encryptor,
        private readonly ComponentSpecification $component,
        private readonly ?string $organizationId,
        private readonly string $projectId,
    ) {
    }

    /**
     * @return array{'#token': string, expiresAt: string}|null
     */
    public function getAuthorization(array $parameters): ?array
    {
        $gitConfig = $parameters['git'] ?? null;
        if (!is_array($gitConfig) || ($gitConfig['auth'] ?? null) !== self::GIT_AUTH_GITHUB_APP) {
            return null;
        }

        if (!$this->component->allowGitHubAppGitAuth()) {
            throw new UserException(sprintf(
                'Component "%s" does not support GitHub App authentication.',
                $this->component->getId(),
            ));
        }

        $repository = $gitConfig['repo'] ?? null;
        if (!is_string($repository) || $repository === '') {
            throw new UserException('GitHub App authentication requires a repository URL in "parameters.git.repo".');
        }

        if ($this->tokenClient === null) {
            throw new ApplicationException('GitHub App token client is not configured.');
        }
        if ($this->organizationId === null || $this->organizationId === '') {
            throw new ApplicationException('Storage API token has no organization.');
        }

        return $this->authorizations[$repository] ??= $this->mintAuthorization($this->tokenClient, $repository);
    }

    /**
     * @return array{'#token': string, expiresAt: string}
     */
    private function mintAuthorization(GitHubAppTokenClient $tokenClient, string $repository): array
    {
        $minted = $tokenClient->mintToken(
            (string) $this->organizationId,
            $this->projectId,
            $this->component->getId(),
            $repository,
        );

        try {
            $token = $this->encryptor->decrypt($minted->encryptedToken);
        } catch (EncryptorUserException | EncryptorApplicationException $e) {
            // the encryptor message contains the cipher text, so it is not passed on
            throw new ApplicationException(sprintf(
                'Failed to decrypt the GitHub App token for repository "%s".',
                $repository,
            ));
        }

        return [
            '#token' => $token,
            'expiresAt' => $minted->expiresAt,
        ];
    }
}
