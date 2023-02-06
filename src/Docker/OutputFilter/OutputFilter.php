<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\OutputFilter;

use Keboola\DockerBundle\Docker\Container\WtfWarningFilter;
use function Keboola\Utils\sanitizeUtf8;

class OutputFilter implements OutputFilterInterface
{
    private const REPLACEMENT = '[hidden]';
    private const TRIMMED = '[trimmed]';

    private array $filterValues = [];
    private int $maxMessageChars;

    public function __construct(int $maxMessageChars)
    {
        $this->maxMessageChars = $maxMessageChars;
    }

    /**
     * @inheritdoc
     */
    public function addValue(string $value): void
    {
        $this->filterValues[] = $value;
        // this is reversible, so hide it too
        $this->filterValues[] = base64_encode($value);
        $this->filterValues[] = json_encode($value);
    }

    /**
     * @inheritdoc
     */
    public function collectValues(array $data): void
    {
        array_walk_recursive($data, function ($value, $key) {
            if ((str_starts_with((string) $key, '#')) && (is_scalar($value))) {
                $this->addValue((string) $value);
            }
        });
    }

    public function filter(string $text): string
    {
        return $this->filterGarbage($this->filterSecrets($text));
    }

    private function filterSecrets(string $text): string
    {
        foreach ($this->filterValues as $filterValue) {
            $text = str_replace($filterValue, self::REPLACEMENT, $text);
        }
        return $text;
    }

    private function filterGarbage(string $value): string
    {
        if (mb_strlen($value) > $this->maxMessageChars) {
            $value = mb_substr($value, 0, $this->maxMessageChars) . ' ' . self::TRIMMED;
        }
        return WtfWarningFilter::filter(trim((string) sanitizeUtf8($value)));
    }
}
