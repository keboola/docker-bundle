<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Monolog;

use DateTimeImmutable;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

// @phpstan-ignore-next-line Logger is marked as @final using PHPdoc, fine until it's final class
class ContainerLogger extends Logger
{
    /**
     * Translates Syslog log priorities to Monolog log levels.
     */
    protected array $logLevels = [
        7 => Level::Debug,
        6 => Level::Info,
        5 => Level::Notice,
        4 => Level::Warning,
        3 => Level::Error,
        2 => Level::Critical,
        1 => Level::Alert,
        0 => Level::Emergency,
    ];


    protected function syslogToMonologLevel(int $level): Level
    {
        return $this->logLevels[$level] ?? Level::Error;
    }

    /**
     * Adds a log record.
     *
     * @param int $level The logging level
     * @param string $message The log message
     * @param array $context The log context
     * @return bool Whether the record has been processed
     */
    public function addRawRecord(int $level, int $timestamp, string $message, array $context = []): bool
    {
        if (!$this->handlers) {
            $this->pushHandler(new StreamHandler('php://stderr', static::DEBUG));
        }

        $level = $this->syslogToMonologLevel($level);

        if ($this->microsecondTimestamps) {
            $ts = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp), $this->getTimezone());
        } else {
            $ts = DateTimeImmutable::createFromFormat('U', sprintf('%.0F', $timestamp), $this->getTimezone());
        }
        assert($ts !== false);
        $ts = $ts->setTimezone($this->getTimezone());

        $logRecord = new LogRecord(
            datetime: $ts,
            channel: $this->name,
            level: $level,
            message: $message,
            context: $context,
            extra: [],
        );

        // check if any handler will handle this message, so we can return early and save cycles
        $handlerKey = null;
        reset($this->handlers);
        while ($handler = current($this->handlers)) {
            if ($handler->isHandling($logRecord)) {
                $handlerKey = key($this->handlers);
                break;
            }

            next($this->handlers);
        }

        if ($handlerKey === null) {
            return false;
        }

        foreach ($this->processors as $processor) {
            $logRecord = call_user_func($processor, $logRecord);
        }

        while ($handler = current($this->handlers)) {
            if ($handler->handle($logRecord) === true) {
                break;
            }

            next($this->handlers);
        }

        return true;
    }
}
