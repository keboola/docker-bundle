<?php

namespace Keboola\DockerBundle\Tests\Service;

use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class StorageApiServiceTest extends TestCase
{
    public function testGetClient()
    {
        $logger = new TestLogger();
        $request = new Request([], [], [], [], [], ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $storageApiService = new StorageApiService($requestStack, STORAGE_API_URL, $logger);
        $client = $storageApiService->getClient();
        self::assertEquals(STORAGE_API_URL, $client->getApiUrl());
        self::assertEquals(STORAGE_API_TOKEN, $client->token);
        $reflection = new \ReflectionProperty(Client::class, 'logger');
        $reflection->setAccessible(true);
        self::assertSame($logger, $reflection->getValue($client));
    }

    public function testGetClientWithoutLogger()
    {
        $logger = new TestLogger();
        $request = new Request([], [], [], [], [], ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $storageApiService = new StorageApiService($requestStack, STORAGE_API_URL, $logger);
        $client = $storageApiService->getClientWithoutLogger();
        self::assertEquals(STORAGE_API_URL, $client->getApiUrl());
        self::assertEquals(STORAGE_API_TOKEN, $client->token);
        $reflection = new \ReflectionProperty(Client::class, 'logger');
        $reflection->setAccessible(true);
        self::assertInstanceOf(NullLogger::class, $reflection->getValue($client));
    }
}
