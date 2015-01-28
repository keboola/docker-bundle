<?php
namespace Keboola\DockerBundle\Monolog\Formatter;
use Syrup\ComponentBundle\Monolog\Formatter\SyrupJsonFormatter;

class DockerBundleJsonFormatter extends SyrupJsonFormatter
{
    /**
     * @param String $appName
     * @return $this
     */
    public function setAppName($appName)
    {
        $this->appName = $appName;
        return $this;
    }

    /**
     * @param array $record
     * @return mixed|string
     */
    public function format(array $record)
    {
        $record["componentParent"] = 'docker';
        return parent::format($record);
    }
}
