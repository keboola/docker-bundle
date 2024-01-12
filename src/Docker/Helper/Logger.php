<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Helper;

class Logger
{
    public const MAX_LENGTH = 4000;

    public static function truncateMessage(string $message, int $maxLength = self::MAX_LENGTH): string
    {
        $ellipsis  = ' ... ';
        $partLength = intdiv($maxLength - mb_strlen($ellipsis), 2);

        if (mb_strlen($message) > $maxLength) {
            $message = mb_substr($message, 0, $partLength) . ' ... ' . mb_substr($message, $partLength * -1);
        }

        return $message;
    }
}
