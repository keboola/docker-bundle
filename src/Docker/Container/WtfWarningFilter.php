<?php

namespace Keboola\DockerBundle\Docker\Container;

class WtfWarningFilter
{
    const WTF_WARNING = 'WARNING: Your kernel does not support swap limit capabilities or the cgroup is not mounted. Memory limited without swap.';

    /**
     * @param string $message
     * @return string
     */
    public static function filter($message)
    {
        $message = str_replace(self::WTF_WARNING, '', $message);
        return trim($message);
    }
}
