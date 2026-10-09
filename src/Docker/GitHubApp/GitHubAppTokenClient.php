<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\GitHubApp;

use Closure;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use JsonException;
use Keboola\ApiClientBase\ApiClient;
use Keboola\ApiClientBase\ApiClientOptions;
use Keboola\ApiClientBase\Auth\KeboolaServiceAccountAuthenticator;
use Keboola\ApiClientBase\Auth\ManageApiTokenAuthenticator;
use Keboola\ApiClientBase\Exception\ClientException;
use Keboola\ApiClientBase\Json;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;

/**
 * Mints a short-lived, read-only GitHub App installation token for one repository.
 *
 * oauth-service accepts only an application identity here, never a Storage token: the
 * APPLICATION_TOKEN in development, the projected Connection ServiceAccount token in production.
 * The token comes back encrypted for the project and component.
 */
class GitHubAppTokenClient
{
    private const MINT_PATH = '/organization-credentials/token';
    private const USER_AGENT = 'Keboola Docker Runner';

    /**
     * oauth-service uses these for requests that cannot succeed as they are (unknown project or
     * host, repository not covered by the installation). 401 and 403 are about the caller's own
     * identity, so they are a platform error, not a user error.
     */
    private const USER_ERROR_STATUS_CODES = [400, 404, 412, 422];

    private readonly ApiClient $apiClient;

    /**
     * @param non-empty-string $oauthServiceUrl
     * @param non-empty-string $serviceAccountTokenPath
     */
    public function __construct(
        string $oauthServiceUrl,
        ?string $applicationToken = null,
        string $serviceAccountTokenPath = KeboolaServiceAccountAuthenticator::DEFAULT_TOKEN_PATH,
        null|Closure|HandlerStack $requestHandler = null,
    ) {
        $authenticator = ($applicationToken !== null && $applicationToken !== '')
            ? new ManageApiTokenAuthenticator($applicationToken)
            : new KeboolaServiceAccountAuthenticator($serviceAccountTokenPath);

        $this->apiClient = new ApiClient(
            $oauthServiceUrl,
            $authenticator,
            new ApiClientOptions(
                userAgent: self::USER_AGENT,
                requestHandler: $requestHandler,
            ),
        );
    }

    public function mintToken(
        string $organizationId,
        string $projectId,
        string $componentId,
        string $repository,
    ): MintedGitHubAppToken {
        try {
            $response = $this->apiClient->sendRequest(new Request(
                'POST',
                self::MINT_PATH,
                ['Content-Type' => 'application/json'],
                Json::encodeArray([
                    'organizationId' => $organizationId,
                    'projectId' => $projectId,
                    'componentId' => $componentId,
                    'repository' => $repository,
                ]),
            ));
        } catch (ClientException $e) {
            $message = sprintf(
                'Failed to get a GitHub App token for repository "%s": %s',
                $repository,
                $e->getMessage(),
            );
            if (in_array($e->getStatusCode(), self::USER_ERROR_STATUS_CODES, true)) {
                throw new UserException($message, $e);
            }
            throw new ApplicationException($message, $e);
        }

        try {
            $data = Json::decodeArray($response->getBody()->getContents());
        } catch (JsonException $e) {
            throw new ApplicationException('oauth-service returned an invalid GitHub App token response.', $e);
        }

        $encryptedToken = $data['#token'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;
        if (!is_string($encryptedToken) || $encryptedToken === '' || !is_string($expiresAt)) {
            throw new ApplicationException('oauth-service returned an invalid GitHub App token response.');
        }

        return new MintedGitHubAppToken($encryptedToken, $expiresAt);
    }
}
