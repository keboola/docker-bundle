<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\GitHubApp;

use Keboola\DockerBundle\Docker\GitHubApp\GitHubAppAuthorization;
use Keboola\DockerBundle\Docker\GitHubApp\GitHubAppTokenClient;
use Keboola\DockerBundle\Docker\GitHubApp\MintedGitHubAppToken;
use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueue\JobConfiguration\JobDefinition\Component\ComponentSpecification;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\Exception\UserException as EncryptorUserException;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;

class GitHubAppAuthorizationTest extends TestCase
{
    private const GITHUB_APP_PARAMETERS = [
        'git' => [
            'repo' => 'https://slsp.ghe.com/org/repo.git',
            'auth' => 'githubApp',
        ],
    ];

    public function testNoGitHubAppAuth(): void
    {
        $authorization = new GitHubAppAuthorization(
            $this->createTokenClientNeverCalled(),
            $this->createEncryptorNeverCalled(),
            $this->createComponent([]),
            '123',
            '456',
        );

        self::assertNull($authorization->getAuthorization(['git' => ['repo' => 'https://github.com/org/repo.git']]));
        self::assertNull($authorization->getAuthorization([]));
    }

    public function testTokenIsMintedAndDecrypted(): void
    {
        $tokenClient = $this->createMock(GitHubAppTokenClient::class);
        $tokenClient->expects(self::once())
            ->method('mintToken')
            ->with('123', '456', 'keboola.dbt-transformation', 'https://slsp.ghe.com/org/repo.git')
            ->willReturn(new MintedGitHubAppToken('KBC::ProjectSecure::token', '2026-10-09T12:00:00+00:00'));

        $encryptor = $this->createMock(JobScopedEncryptor::class);
        $encryptor->expects(self::once())
            ->method('decrypt')
            ->with('KBC::ProjectSecure::token')
            ->willReturn('ghs_plain-token');

        $authorization = new GitHubAppAuthorization(
            $tokenClient,
            $encryptor,
            $this->createComponent(['organization-github-app-auth']),
            '123',
            '456',
        );

        $expected = [
            '#token' => 'ghs_plain-token',
            'expiresAt' => '2026-10-09T12:00:00+00:00',
        ];
        self::assertSame($expected, $authorization->getAuthorization(self::GITHUB_APP_PARAMETERS));
        self::assertSame($expected, $authorization->getAuthorization(self::GITHUB_APP_PARAMETERS));
    }

    public function testComponentWithoutFeature(): void
    {
        $authorization = new GitHubAppAuthorization(
            $this->createTokenClientNeverCalled(),
            $this->createEncryptorNeverCalled(),
            $this->createComponent([]),
            '123',
            '456',
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Component "keboola.dbt-transformation" does not support GitHub App authentication.',
        );

        $authorization->getAuthorization(self::GITHUB_APP_PARAMETERS);
    }

    public function testMissingRepository(): void
    {
        $authorization = new GitHubAppAuthorization(
            $this->createTokenClientNeverCalled(),
            $this->createEncryptorNeverCalled(),
            $this->createComponent(['organization-github-app-auth']),
            '123',
            '456',
        );

        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'GitHub App authentication requires a repository URL in "parameters.git.repo".',
        );

        $authorization->getAuthorization(['git' => ['auth' => 'githubApp']]);
    }

    public function testMissingTokenClient(): void
    {
        $authorization = new GitHubAppAuthorization(
            null,
            $this->createEncryptorNeverCalled(),
            $this->createComponent(['organization-github-app-auth']),
            '123',
            '456',
        );

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('GitHub App token client is not configured.');

        $authorization->getAuthorization(self::GITHUB_APP_PARAMETERS);
    }

    public function testMissingOrganization(): void
    {
        $authorization = new GitHubAppAuthorization(
            $this->createTokenClientNeverCalled(),
            $this->createEncryptorNeverCalled(),
            $this->createComponent(['organization-github-app-auth']),
            null,
            '456',
        );

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Storage API token has no organization.');

        $authorization->getAuthorization(self::GITHUB_APP_PARAMETERS);
    }

    public function testDecryptFailureDoesNotLeakCipherText(): void
    {
        $tokenClient = $this->createMock(GitHubAppTokenClient::class);
        $tokenClient->expects(self::once())
            ->method('mintToken')
            ->willReturn(new MintedGitHubAppToken('KBC::ProjectSecure::token', '2026-10-09T12:00:00+00:00'));

        $encryptor = $this->createMock(JobScopedEncryptor::class);
        $encryptor->expects(self::once())
            ->method('decrypt')
            ->willThrowException(new EncryptorUserException('Value KBC::ProjectSecure::token is not encrypted.'));

        $authorization = new GitHubAppAuthorization(
            $tokenClient,
            $encryptor,
            $this->createComponent(['organization-github-app-auth']),
            '123',
            '456',
        );

        try {
            $authorization->getAuthorization(self::GITHUB_APP_PARAMETERS);
            self::fail('Decryption failure should be reported.');
        } catch (ApplicationException $e) {
            self::assertSame(
                'Failed to decrypt the GitHub App token for repository "https://slsp.ghe.com/org/repo.git".',
                $e->getMessage(),
            );
            self::assertNull($e->getPrevious());
        }
    }

    public function testTokenIsWrittenToConfigFileAndMasked(): void
    {
        $tokenClient = $this->createMock(GitHubAppTokenClient::class);
        $tokenClient->expects(self::once())
            ->method('mintToken')
            ->willReturn(new MintedGitHubAppToken('KBC::ProjectSecure::token', '2026-10-09T12:00:00+00:00'));

        $encryptor = $this->createMock(JobScopedEncryptor::class);
        $encryptor->expects(self::once())
            ->method('decrypt')
            ->willReturn('ghs_plain-token');

        $oauthClient = $this->createMock(Credentials::class);
        $oauthClient->expects(self::never())->method('getDetail');

        $authorization = new Authorization(
            $oauthClient,
            $encryptor,
            'keboola.dbt-transformation',
            new GitHubAppAuthorization(
                $tokenClient,
                $encryptor,
                $this->createComponent(['organization-github-app-auth']),
                '123',
                '456',
            ),
        );

        $temp = new Temp();
        $outputFilter = new OutputFilter(10000);
        $configFile = new ConfigFile($temp->getTmpFolder(), $authorization, 'run', 'json');
        $configFile->createConfigFile(
            [
                'parameters' => self::GITHUB_APP_PARAMETERS,
                'authorization' => [
                    'githubApp' => ['#token' => 'user-provided-token'],
                ],
            ],
            $outputFilter,
            [],
            [],
        );

        $data = json_decode((string) file_get_contents($temp->getTmpFolder() . '/config.json'), true);
        self::assertIsArray($data);
        self::assertSame(
            [
                'githubApp' => [
                    '#token' => 'ghs_plain-token',
                    'expiresAt' => '2026-10-09T12:00:00+00:00',
                ],
            ],
            $data['authorization'],
        );
        self::assertSame('token: [hidden]', $outputFilter->redactSecrets('token: ghs_plain-token'));
    }

    public function testConfigWithoutGitHubAppAuthHasEmptyAuthorization(): void
    {
        $oauthClient = $this->createMock(Credentials::class);
        $oauthClient->expects(self::never())->method('getDetail');

        $authorization = new Authorization(
            $oauthClient,
            $this->createEncryptorNeverCalled(),
            'keboola.dbt-transformation',
            new GitHubAppAuthorization(
                $this->createTokenClientNeverCalled(),
                $this->createEncryptorNeverCalled(),
                $this->createComponent(['organization-github-app-auth']),
                '123',
                '456',
            ),
        );

        $temp = new Temp();
        $configFile = new ConfigFile($temp->getTmpFolder(), $authorization, 'run', 'json');
        $configFile->createConfigFile(
            ['parameters' => ['git' => ['repo' => 'https://github.com/org/repo.git']]],
            new OutputFilter(10000),
            [],
            [],
        );

        $data = json_decode((string) file_get_contents($temp->getTmpFolder() . '/config.json'), true);
        self::assertIsArray($data);
        self::assertSame([], $data['authorization']);
    }

    /**
     * @param list<string> $features
     */
    private function createComponent(array $features): ComponentSpecification
    {
        return new ComponentSpecification([
            'id' => 'keboola.dbt-transformation',
            'data' => [
                'definition' => [
                    'type' => 'dockerhub',
                    'uri' => 'dummy',
                ],
            ],
            'features' => $features,
        ]);
    }

    private function createTokenClientNeverCalled(): GitHubAppTokenClient
    {
        $tokenClient = $this->createMock(GitHubAppTokenClient::class);
        $tokenClient->expects(self::never())->method('mintToken');

        return $tokenClient;
    }

    private function createEncryptorNeverCalled(): JobScopedEncryptor
    {
        $encryptor = $this->createMock(JobScopedEncryptor::class);
        $encryptor->expects(self::never())->method('decrypt');

        return $encryptor;
    }
}
