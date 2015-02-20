<?php
namespace Keboola\DockerBundle\Monolog\Formatter;

use Syrup\ComponentBundle\Monolog\Formatter\JsonFormatter;

class DockerBundleJsonFormatter extends JsonFormatter
{
    /**
     * @var
     */
    protected $appName;

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
     * @return mixed
     */
    public function getAppName()
    {
        return $this->appName;
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
