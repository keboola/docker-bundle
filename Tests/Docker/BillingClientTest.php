<?php

namespace Keboola\DockerBundle\Tests\Docker;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Keboola\DockerBundle\Docker\BillingClient;
use Keboola\DockerBundle\Exception\BillingClientException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;

class BillingClientTest extends TestCase
{
    private function getClient(array $options, LoggerInterface $logger = null)
    {
        return new BillingClient(
            $logger ? $logger : new NullLogger(),
            'http://example.com/',
            'testToken',
            $options
        );
    }

    public function testCreateClientInvalidBackoff()
    {
        self::expectException(BillingClientException::class);
        self::expectExceptionMessage(
            'Invalid parameters when creating client: Value "abc" is invalid: This value should be a valid number'
        );
        new BillingClient(
            new NullLogger(),
            'http://example.com/',
            'testToken',
            ['backoffMaxTries' => 'abc']
        );
    }

    public function testCreateClientTooLowBackoff()
    {
        self::expectException(BillingClientException::class);
        self::expectExceptionMessage(
            'Invalid parameters when creating client: Value "-1" is invalid: This value should be 0 or more'
        );
        new BillingClient(
            new NullLogger(),
            'http://example.com/',
            'testToken',
            ['backoffMaxTries' => -1]
        );
    }

    public function testCreateClientTooHighBackoff()
    {
        self::expectException(BillingClientException::class);
        self::expectExceptionMessage(
            'Invalid parameters when creating client: Value "101" is invalid: This value should be 100 or less'
        );
        new BillingClient(
            new NullLogger(),
            'http://example.com/',
            'testToken',
            ['backoffMaxTries' => 101]
        );
    }

    public function testCreateClientInvalidToken()
    {
        self::expectException(BillingClientException::class);
        self::expectExceptionMessage(
            'Invalid parameters when creating client: Value "" is invalid: This value should not be blank.'
        );
        new BillingClient(new NullLogger(), 'http://example.com/', '');
    }

    public function testCreateClientInvalidUrl()
    {
        self::expectException(BillingClientException::class);
        self::expectExceptionMessage(
            'Invalid parameters when creating client: Value "invalid url" is invalid: This value is not a valid URL.'
        );
        new BillingClient(new NullLogger(), 'invalid url', 'testToken');
    }

    public function testCreateClientMultipleErrors()
    {
        self::expectException(BillingClientException::class);
        self::expectExceptionMessage(
            'Invalid parameters when creating client: Value "invalid url" is invalid: This value is not a valid URL.'
        );
        new BillingClient(new NullLogger(), 'invalid url', '');
    }

    public function testClientRequestResponse()
    {
        $mock = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                '{
                    "remaining": "123.4343434343434343",
                    "consumed": "456.1212121212121212"
                }'
            ),
        ]);
        // Add the history middleware to the handler stack.
        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = $this->getClient(['handler' => $stack]);
        $credits = $client->getRemainingCredits();
        self::assertEquals(123.43434343434, $credits);
        self::assertCount(1, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        self::assertEquals('http://example.com/credits', $request->getUri()->__toString());
        self::assertEquals('GET', $request->getMethod());
        self::assertEquals('testToken', $request->getHeader('X-StorageApi-Token')[0]);
        self::assertEquals('Internal PHP Client', $request->getHeader('User-Agent')[0]);
        self::assertEquals('application/json', $request->getHeader('Content-type')[0]);
    }

    public function testInvalidResponse()
    {
        $mock = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                'invalid json'
            ),
        ]);
        // Add the history middleware to the handler stack.
        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = $this->getClient(['handler' => $stack]);
        self::expectException(BillingClientException::class);
        self::expectExceptionMessage('Unable to parse response body into JSON: Syntax error');
        $client->getRemainingCredits();
    }

    public function testLogger()
    {
        $mock = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                '{
                    "remaining": "123",
                    "consumed": "456"
                }'
            ),
        ]);
        // Add the history middleware to the handler stack.
        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $logger = new TestLogger();
        $client = $this->getClient(['handler' => $stack, 'logger' => $logger, 'userAgent' => 'test agent']);
        $client->getRemainingCredits();
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        self::assertEquals('test agent', $request->getHeader('User-Agent')[0]);
        self::assertTrue($logger->hasInfoThatContains('"GET  /1.1" 200 '));
        self::assertTrue($logger->hasInfoThatContains('test agent'));
    }

    public function testRetrySuccess()
    {
        $mock = new MockHandler([
            new Response(
                500,
                ['Content-Type' => 'application/json'],
                '{"message" => "Out of order"}'
            ),
            new Response(
                500,
                ['Content-Type' => 'application/json'],
                'Out of order'
            ),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                '{
                    "remaining": "123",
                    "consumed": "456"
                }'
            ),
        ]);
        // Add the history middleware to the handler stack.
        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = $this->getClient(['handler' => $stack]);
        $credits = $client->getRemainingCredits();
        self::assertEquals('123', $credits);
        self::assertCount(3, $requestHistory);
        /** @var Request $request */
        $request = $requestHistory[0]['request'];
        self::assertEquals('http://example.com/credits', $request->getUri()->__toString());
        $request = $requestHistory[1]['request'];
        self::assertEquals('http://example.com/credits', $request->getUri()->__toString());
        $request = $requestHistory[2]['request'];
        self::assertEquals('http://example.com/credits', $request->getUri()->__toString());
    }

    public function testRetryFailure()
    {
        $responses = [];
        for ($i = 0; $i < 30; $i++) {
            $responses[] = new Response(
                500,
                ['Content-Type' => 'application/json'],
                '{"message" => "Out of order"}'
            );
        }
        $mock = new MockHandler($responses);
        // Add the history middleware to the handler stack.
        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = $this->getClient(['handler' => $stack, 'backoffMaxTries' => 1]);
        try {
            $client->getRemainingCredits();
            self::fail('Must throw exception');
        } catch (BillingClientException $e) {
            self::assertContains('500 Internal Server Error', $e->getMessage());
        }
        self::assertCount(2, $requestHistory);
    }

    public function testRetryFailureReducedBackoff()
    {
        $responses = [];
        for ($i = 0; $i < 30; $i++) {
            $responses[] = new Response(
                500,
                ['Content-Type' => 'application/json'],
                '{"message" => "Out of order"}'
            );
        }
        $mock = new MockHandler($responses);
        // Add the history middleware to the handler stack.
        $requestHistory = [];
        $history = Middleware::history($requestHistory);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = $this->getClient(['handler' => $stack, 'backoffMaxTries' => 3]);
        try {
            $client->getRemainingCredits();
            self::fail('Must throw exception');
        } catch (BillingClientException $e) {
            self::assertContains('500 Internal Server Error', $e->getMessage());
        }
        self::assertCount(4, $requestHistory);
    }
}
