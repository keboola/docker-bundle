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

        // default settings
        $truncatedText = Logger::truncateMessage($longText);

        $this->assertSame(3999, mb_strlen($truncatedText));
        $this->assertStringContainsString(' ... ', $truncatedText);

        // custom settings
        $this->assertSame(
            'Lorem ipsum dolor sit amet, ... consectetur adipisici elit.',
            Logger::truncateMessage($longText, 60),
        );
    }
}
