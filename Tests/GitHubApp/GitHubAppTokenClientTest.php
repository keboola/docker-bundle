<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\GitHubApp;

use Closure;
use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Keboola\DockerBundle\Docker\GitHubApp\GitHubAppTokenClient;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class GitHubAppTokenClientTest extends TestCase
{
    public function testMintTokenWithApplicationToken(): void
    {
        /** @var list<RequestInterface> $requests */
        $requests = [];
        $client = new GitHubAppTokenClient(
            'http://oauth-service-api.default',
            'application-token',
            requestHandler: $this->createHandler(
                new Response(200, [], (string) json_encode([
                    '#token' => 'KBC::ProjectSecure::token',
                    'expiresAt' => '2026-10-09T12:00:00+00:00',
                ])),
                $requests,
            ),
        );

        $token = $client->mintToken('123', '456', 'keboola.dbt-transformation', 'https://slsp.ghe.com/org/repo.git');

        self::assertSame('KBC::ProjectSecure::token', $token->encryptedToken);
        self::assertSame('2026-10-09T12:00:00+00:00', $token->expiresAt);

        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'http://oauth-service-api.default/organization-credentials/token',
            (string) $request->getUri(),
        );
        self::assertSame('application-token', $request->getHeaderLine('X-KBC-ManageApiToken'));
        self::assertSame(
            [
                'organizationId' => '123',
                'projectId' => '456',
                'componentId' => 'keboola.dbt-transformation',
                'repository' => 'https://slsp.ghe.com/org/repo.git',
            ],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function testMintTokenWithServiceAccountToken(): void
    {
        $tokenPath = sys_get_temp_dir() . '/sa-token-' . uniqid();
        file_put_contents($tokenPath, "service-account-jwt\n");

        /** @var list<RequestInterface> $requests */
        $requests = [];
        $client = new GitHubAppTokenClient(
            'http://oauth-service-api.default',
            '',
            $tokenPath,
            $this->createHandler(
                new Response(200, [], (string) json_encode(['#token' => 'token', 'expiresAt' => 'later'])),
                $requests,
            ),
        );

        try {
            $client->mintToken('123', '456', 'keboola.dbt-transformation', 'https://slsp.ghe.com/org/repo.git');
        } finally {
            unlink($tokenPath);
        }

        self::assertSame('Bearer service-account-jwt', $requests[0]->getHeaderLine('X-Kubernetes-Authorization'));
        self::assertFalse($requests[0]->hasHeader('X-KBC-ManageApiToken'));
    }

    /**
     * @dataProvider failedMintProvider
     * @param class-string<\Throwable> $expectedException
     */
    public function testMintTokenFailure(int $statusCode, string $expectedException): void
    {
        $requests = [];
        $client = new GitHubAppTokenClient(
            'http://oauth-service-api.default',
            'application-token',
            requestHandler: $this->createHandler(
                new Response($statusCode, [], (string) json_encode(['error' => 'Repository is not covered.'])),
                $requests,
            ),
        );

        try {
            $client->mintToken('123', '456', 'keboola.dbt-transformation', 'https://slsp.ghe.com/org/repo.git');
            self::fail('Mint should fail.');
        } catch (ApplicationException $e) {
            self::assertSame($expectedException, $e::class);
            self::assertSame(
                'Failed to get a GitHub App token for repository "https://slsp.ghe.com/org/repo.git": '
                . 'Repository is not covered.',
                $e->getMessage(),
            );
        }
    }

    public static function failedMintProvider(): Generator
    {
        yield 'repository not covered' => [422, UserException::class];
        yield 'no app registered for host' => [412, UserException::class];
        yield 'no connection for organization' => [404, UserException::class];
        yield 'caller not allowed to mint' => [403, ApplicationException::class];
    }

    public function testMintTokenInvalidResponse(): void
    {
        $requests = [];
        $client = new GitHubAppTokenClient(
            'http://oauth-service-api.default',
            'application-token',
            requestHandler: $this->createHandler(new Response(200, [], '{"expiresAt": "later"}'), $requests),
        );

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('oauth-service returned an invalid GitHub App token response.');

        $client->mintToken('123', '456', 'keboola.dbt-transformation', 'https://slsp.ghe.com/org/repo.git');
    }

    /**
     * Records the request as the innermost handler, so it sees the headers added by the client.
     *
     * @param list<RequestInterface> $requests
     */
    private function createHandler(Response $response, array &$requests): Closure
    {
        $mockHandler = new MockHandler([$response]);

        return function (RequestInterface $request, array $options) use ($mockHandler, &$requests): PromiseInterface {
            $requests[] = $request;
            return $mockHandler($request, $options);
        };
    }
}
