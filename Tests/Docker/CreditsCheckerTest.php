<?php

namespace Keboola\DockerBundle\Tests\Docker;

use Keboola\DockerBundle\Docker\BillingClient;
use Keboola\DockerBundle\Docker\CreditsChecker;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;

class CreditsCheckerTest extends TestCase
{
    public function testCheckCreditsNoBilling()
    {
        $client = $this->getMockBuilder(Client::class)
            ->setMethods(['indexAction'])
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('indexAction')->willReturn(
            [
                'services' => [
                    [
                        'id' => 'graph',
                        'url' => 'https://graph.keboola.com',
                    ],
                    [
                        'id' => 'encryption',
                        'url' => 'https://encryption.keboola.com',
                    ],
                ],
            ]
        );
        /** @var Client $client */
        $creditsChecker = new CreditsChecker($client);
        $this->assertTrue($creditsChecker->hasCredits());
    }

    public function testCheckCreditsNoFeature()
    {
        $client = $this->getMockBuilder(Client::class)
            ->setMethods(['indexAction', 'verifyToken'])
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('indexAction')->willReturn(
            [
                'services' => [
                    [
                        'id' => 'graph',
                        'url' => 'https://graph.keboola.com',
                    ],
                    [
                        'id' => 'encryption',
                        'url' => 'https://encryption.keboola.com',
                    ],
                    [
                        'id' => 'billing',
                        'url' => 'https://billing.keboola.com',
                    ],
                ],
            ]
        );
        $client->method('verifyToken')->willReturn(
            [
                'id' => '123',
                'owner' => [
                    'id' => '123',
                    'name' => 'test',
                    'features' => [
                        'transformation-config-storage',
                    ],
                ]
            ]
        );
        /** @var Client $client */
        $creditsChecker = new CreditsChecker($client);
        self::assertTrue($creditsChecker->hasCredits());
    }

    public function valuesProvider()
    {
        return [
            [-123, false],
            [0, false],
            [0.0001, true],
            [1.0, true],
            [123, true],
        ];
    }

    /**
     * $@dataProvider valuesProvider
     * @param double $remainingCredits
     * @param bool $hasCredits
     */
    public function testCheckCreditsHasFeatureHasCredits($remainingCredits, $hasCredits)
    {
        $client = self::getMockBuilder(Client::class)
            ->setMethods(['indexAction', 'verifyToken'])
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('indexAction')->willReturn(
            [
                'services' => [
                    [
                        'id' => 'graph',
                        'url' => 'https://graph.keboola.com',
                    ],
                    [
                        'id' => 'encryption',
                        'url' => 'https://encryption.keboola.com',
                    ],
                    [
                        'id' => 'billing',
                        'url' => 'https://billing.keboola.com',
                    ],
                ],
            ]
        );
        $client->method('verifyToken')->willReturn(
            [
                'id' => '123',
                'owner' => [
                    'id' => '123',
                    'name' => 'test',
                    'features' => [
                        'transformation-config-storage',
                        'pay-as-you-go',
                    ],
                ]
            ]
        );
        $billingClient = self::getMockBuilder(BillingClient::class)
            ->setMethods(['getRemainingCredits'])
            ->disableOriginalConstructor()
            ->getMock();
        $billingClient->method('getRemainingCredits')
            ->willReturn($remainingCredits);
        /** @var Client $client */
        $creditsChecker = self::getMockBuilder(CreditsChecker::class)
            ->setMethods(['getBillingClient'])
            ->setConstructorArgs([$client])
            ->getMock();
        $creditsChecker->method('getBillingClient')
            ->willReturn($billingClient);
        /** @var CreditsChecker $creditsChecker */
        self::assertEquals($hasCredits, $creditsChecker->hasCredits());
    }
}
