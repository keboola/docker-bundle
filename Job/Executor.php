<?php
namespace Keboola\DockerBundle\Job;
use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\StorageApi\Reader;
use Keboola\DockerBundle\Docker\StorageApi\Writer;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Event;
use Monolog\Logger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Syrup\ComponentBundle\Exception\UserException;
use Syrup\ComponentBundle\Filesystem\Temp;
use Syrup\ComponentBundle\Job\Executor as BaseExecutor;
use Syrup\ComponentBundle\Job\Metadata\Job;

class Executor extends BaseExecutor
{

    /**
     * @var Logger
     */
    protected $log;

    /**
     * @var Temp
     */
    protected $temp;

    /**
     * @param Logger $log
     * @param Temp $temp
     */
    public function __construct(Logger $log, Temp $temp)
    {
        $this->log = $log;
        $this->temp = $temp;
    }

    /**
     * @param Job $job
     * @return array
     * @throws \Exception
     */
    public function execute(Job $job)
    {
        $params = $job->getParams();

        // Check list of components
        $components = $this->storageApi->indexAction();
        foreach($components["components"] as $c) {
            if ($c["id"] == $params["component"]) {
                $component = $c;
            }
        }

        if (!isset($component)) {
            throw new UserException("Component '{$params["component"]}' not found.");
        }

        // Get the formatters and change the component
        foreach ($this->log->getHandlers() as $handler) {
            if (get_class($handler->getFormatter()) == 'Keboola\\DockerBundle\\Monolog\\Formatter\\DockerBundleJsonFormatter') {
                $handler->getFormatter()->setAppName($params["component"]);
            }
        }

        // Parse data from component
        $image = Image::factory($component["data"]);



        $fs = new Filesystem();
        $tmpDir = $this->temp->getTmpFolder();
        $fs->mkdir($tmpDir);

        // TODO Manual config

        // Read config
        try {
            $components = new Components($this->storageApi);
            $configData = $components->getConfiguration($component["id"], $params["config"]);
        } catch(ClientException $e) {
            throw new UserException("Error reading configuration '{$params["config"]}': " . $e->getMessage(), $e);
        }

        try {
            $config = (new \Keboola\DockerBundle\Docker\Configuration\Container())->parse(array("config" => $configData["configuration"]));
        } catch (InvalidConfigurationException $e) {
            throw new UserException("Parsing configuration failed: " . $e->getMessage(), $e);
        }

        $container = new Container($image);
        $container->createDataDir($tmpDir);
        $reader = new Reader($this->storageApi);
        $reader->setFormat($image->getConfigFormat());

        try {
            if (isset($config["storage"]["input"]["tables"]) && count($config["storage"]["input"]["tables"])) {
                $reader->downloadTables($config["storage"]["input"]["tables"], $tmpDir . "/data/in/tables");
            }
            if (isset($config["storage"]["input"]["files"]) && count($config["storage"]["input"]["files"])) {
                $reader->downloadFiles($config["storage"]["input"]["tables"], $tmpDir . "/data/in/files");
            }
        } catch(ClientException $e) {
            throw new UserException("Cannot import data from Storage API: " . $e->getMessage(), $e);
        }

        $adapter = new Configuration\Container\Adapter();
        $adapter->setFormat($image->getConfigFormat());
        $adapter->setConfig($config);
        switch($adapter->getFormat()) {
            case 'yaml':
                $fileSuffix = ".yml";
                break;
            case 'json':
                $fileSuffix = ".json";
                break;
        }
        $adapter->writeToFile($tmpDir . "/data/config" . $fileSuffix);


        $this->log->info("Running Docker container for '{$component['id']}'", $config);

        $image->prepare($container);
        $process = $container->run();

        $this->log->info($process->getOutput());
        $this->log->info("Docker container for '{$component['id']}' finished.");

        $writer = new Writer($this->storageApi);
        $writer->setFormat($image->getConfigFormat());

        $outputTablesConfig = array();
        $outputFilesConfig = array();

        if (isset($config["storage"]["output"]["tables"]) && count($config["storage"]["output"]["tables"])) {
            $outputTablesConfig = $config["storage"]["output"]["tables"];
        }
        if (isset($config["storage"]["output"]["files"]) && count($config["storage"]["output"]["files"])) {
            $outputFilesConfig = $config["storage"]["output"]["files"];
        }

        try {
            $writer->uploadTables($tmpDir . "/data/out/tables", $outputTablesConfig);
            $writer->uploadFiles($tmpDir . "/data/out/files", $outputFilesConfig);
        } catch(ClientException $e) {
            throw new UserException("Cannot export data to Storage API: " . $e->getMessage(), $e);
        }

        $container->dropDataDir();

        return ["message" => "Hello Job " . $job->getId()];
    }

}