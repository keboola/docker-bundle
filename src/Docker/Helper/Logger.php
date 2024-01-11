<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Helper;

use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\InputMapping\Table\Result\Column as ColumnInfo;
use Keboola\InputMapping\Table\Result\TableInfo;
use Keboola\InputMapping\Table\Result\TableMetrics as InputTableMetrics;
use Keboola\JobQueueInternalClient\JobFactory\Runtime\Backend;
use Keboola\JobQueueInternalClient\Result\InputOutput\Column;
use Keboola\JobQueueInternalClient\Result\InputOutput\ColumnCollection;
use Keboola\JobQueueInternalClient\Result\InputOutput\Table;
use Keboola\JobQueueInternalClient\Result\InputOutput\TableCollection;
use Keboola\JobQueueInternalClient\Result\JobArtifacts;
use Keboola\JobQueueInternalClient\Result\JobMetrics;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\JobQueueInternalClient\Result\Variable\Variable;
use Keboola\JobQueueInternalClient\Result\Variable\VariableCollection;
use Keboola\OutputMapping\Table\Result\TableMetrics as OutputTableMetrics;

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
