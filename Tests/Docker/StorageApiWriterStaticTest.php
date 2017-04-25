<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\TableExporter;
use Keboola\Temp\Temp;
use Keboola\Syrup\Exception\UserException;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class StorageApiWriterStaticTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider normalizePrimaryKeyProvider
     */

    public function testNormalizePrimaryKey(array $pkey, array $result)
    {
        $this->assertEquals($result, Writer::normalizePrimaryKey($pkey));
    }

    /**
     * @return array
     */
    public function normalizePrimaryKeyProvider()
    {
        return [
            [
                [""],
                []
            ],
            [
                [""],
                []
            ],
            [
                ["Id", "Id"],
                ["Id"]
            ],
            [
                ["Id", "Name"],
                ["Id", "Name"]
            ]
        ];
    }
}
