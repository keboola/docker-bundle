<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Helper;

use Keboola\DockerBundle\Docker\Helper\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function testTruncateMessage(): void
    {
        $text = 'Lorem ipsum dolor sit amet, consectetur adipisici elit.';
        $longText = str_repeat($text, 100); // 5500 chars

        $truncatedText = Logger::truncateMessage($longText);

        $this->assertSame(4005, mb_strlen($truncatedText));
        $this->assertStringContainsString(' ... ', $truncatedText);
    }
}
