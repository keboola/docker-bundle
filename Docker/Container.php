<?php

namespace Keboola\DockerBundle\Docker;

use Docker\Docker;
use Docker\Http\DockerClient;
use Monolog\Logger;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Namshi\Cuzzle\Formatter\CurlFormatter;
use GuzzleHttp\Message\Request;


class Container
{
    /**
     *
     * Image Id
     *
     * @var string
     */
    protected $id;

    /**
     * @var Image
     */
    protected $image;

    /**
     * @var string
     */
    protected $version = 'latest';

    /**
     * @var string
     */
    protected $dataDir;

    /**
     * @var array
     */
    protected $environmentVariables = array();

    /**
     * @var Logger
     */
    private $log;

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return Image
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * @param Image $image
     * @return $this
     */
    public function setImage($image)
    {
        $this->image = $image;
        return $this;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @param string $version
     * @return $this
     */
    public function setVersion($version)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * @param Image $image
     * @param Logger $logger
     */
    public function __construct(Image $image, Logger $logger)
    {
        $this->log = $logger;
        $this->setImage($image);
    }

    /**
     * @return string
     */
    public function getDataDir()
    {
        return $this->dataDir;
    }

    /**
     * @param string $dataDir
     * @return $this
     */
    public function setDataDir($dataDir)
    {
        $this->dataDir = $dataDir;
        return $this;
    }

    /**
     * @return array
     */
    public function getEnvironmentVariables()
    {
        return $this->environmentVariables;
    }

    /**
     * @param array $environmentVariables
     * @return $this
     */
    public function setEnvironmentVariables($environmentVariables)
    {
        $this->environmentVariables = $environmentVariables;
        return $this;
    }

    /**
     * @param string $containerName suffix to the container tag
     * @return Process
     * @throws ApplicationException
     */
    public function run($containerName = "")
    {
        if (!$this->getDataDir()) {
            throw new ApplicationException("Data directory not set.");
        }

        $id = $this->getImage()->prepare($this);
        $this->setId($id);

        $this->getId();

        try {

            //$client = new DockerClient(array(), 'tcp://127.0.0.1:2022');
            //$client = new DockerClient(array(), 'tcp://192.168.59.103:2022');
            //$client = new DockerClient();
            //$client = new DockerClient([], 'tcp://192.168.59.103:2376', null, true);
            $options = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            //$client = new DockerClient([], null, $options, true);
            $client = new DockerClient();
      //      $client->
            //$client = new DockerClient(array(), 'https://192.168.59.103:2376');
            //$client = new DockerClient(array(), 'tcp://192.168.59.103:2375');
            //http:///
//            $client = new DockerClient(array(), 'tcp://docker');
            $docker = new Docker($client);

         //   $request = new Request('GET', 'example.local');

        //    echo (new CurlFormatter())->format($request);

         //   $l = $docker->getImageManager()->findAll();
            $container = new \Docker\Container(['Image' => 'keboola/docker-lgr-bundle']);
            $manager = $docker->getContainerManager();
            /*
            $manager->create($container);

            printf('Created container with Id "%s"', $container->getId());

            $manager->attach($container, function ($output, $type) {
                print('o1'.$output);
            });

            $manager->start($container);
            $manager->wait($container);

  //          The attach() method can also retrieve logs from a stopped container.

//<?php

            $manager->attach($container, function ($output, $type) {
                print('o2' . $output);
            }, true);
*/
            //$container = new \Docker\Container(['Image' => 'keboola/docker-lgr-bundle']);
            //$docker->getContainerManager()->run($container);

            $manager = $docker->getContainerManager();
//            $ret = $manager->run($container, function ($output, $type) {
//                $this->log->info($type . ':' . $output);
/*                if ($type === 1) {
                    $this->log->info($output);
                } else {
                    $this->log->err($output);
                }
*/
//            });

  //          if (!$ret) {
  //              throw new \Exception('process failed');
    //        }

        } catch (\Exception $e) {
            $this->log->err($e->getMessage());
            throw $e;
        }


        $process = new Process($this->getRunCommand($containerName));
        $process->setTimeout($this->getImage()->getProcessTimeout());

        try {
            $this->log->debug("Executing process");
            $log = $this->log;
            $process->run(function ($type, $buffer) use ($log) {
                if ($type === Process::ERR) {
                    $this->log->warn($buffer);
                } else {
                    $this->log->info($buffer);
                }
            });
            $this->log->debug("Process finished");
        } catch (ProcessTimedOutException $e) {
            throw new UserException(
                "Running container exceeded the timeout of {$this->getImage()->getProcessTimeout()} seconds."
            );
        }

        $this->log->warn("eo: ". $process->getErrorOutput());
        $this->log->info("so: ". $process->getOutput());
        if (!$process->isSuccessful()) {
            $message = substr($process->getErrorOutput(), 0, 8192);
            if (!$message) {
                $message = substr($process->getOutput(), 0, 8192);
            }
            if (!$message) {
                $message = "No error message.";
            }
            $data = [
                "output" => substr($process->getOutput(), 0, 8192),
                "errorOutput" => substr($process->getErrorOutput(), 0, 8192)
            ];

            if ($process->getExitCode() == 1) {
                throw new UserException("Container '{$this->getId()}' failed: {$message}", null, $data);
            } else {
                // syrup will make sure that the actual exception message will be hidden to end-user
                throw new ApplicationException(
                    "Container '{$this->getId()}' failed: ({$process->getExitCode()}) {$message}",
                    null,
                    $data
                );
            }
        }
        return $process;
    }

    /**
     * @param $root
     * @return $this
     */
    public function createDataDir($root)
    {
        $fs = new Filesystem();
        $structure = array(
            $root . "/data",
            $root . "/data/in",
            $root . "/data/in/tables",
            $root . "/data/in/files",
            $root . "/data/out",
            $root . "/data/out/tables",
            $root . "/data/out/files"
        );

        $fs->mkdir($structure);
        $this->setDataDir($root . "/data");
        return $this;
    }

    /**
     * Remove whole directory structure
     */
    public function dropDataDir()
    {
        $fs = new Filesystem();
        $structure = array(
            $this->getDataDir() . "/in/tables",
            $this->getDataDir() . "/in/files",
            $this->getDataDir() . "/in",
            $this->getDataDir() . "/out/files",
            $this->getDataDir() . "/out/tables",
            $this->getDataDir() . "/out",
            $this->getDataDir()
        );
        $finder = new Finder();
        $finder->files()->in($structure);
        $fs->remove($finder);
        $fs->remove($structure);
    }

    /**
     * @param string $containerName
     * @return string
     */
    public function getRunCommand($containerName = "")
    {
        $this->id = "keboola/docker-lgr-bundle";
        $this->setEnvironmentVariables(['command' => '/data/test.php']);
        setlocale(LC_CTYPE, "en_US.UTF-8");
        $envs = "";
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $dataDir = str_replace(DIRECTORY_SEPARATOR, '/', str_replace(':', '', '/' . lcfirst($this->dataDir)));
            foreach ($this->getEnvironmentVariables() as $key => $value) {
                $envs .= " -e " . escapeshellarg($key) . "=" . str_replace(' ', '\\ ', escapeshellarg($value));
            }
            $command = "docker run";
        } else {
            $dataDir = $this->dataDir;
            foreach ($this->getEnvironmentVariables() as $key => $value) {
            //    $envs .= " -e \"" . str_replace('"', '\"', $key) . "=" . str_replace('"', '\"', $value). "\"";
            }
            $command = "sudo docker run";
        }

        $this->id = "keboola/docker-php-test";
        $dataDir = "/c/Users/Odin/D/";
        $command .= " --volume=" . escapeshellarg($dataDir) . ":/data"
            . " --memory=" . escapeshellarg($this->getImage()->getMemory())
            . " --cpu-shares=" . escapeshellarg($this->getImage()->getCpuShares())
            . $envs
            . " --rm"
            . " --name=" . escapeshellarg(strtr($this->getId(), ":/", "--") . ($containerName ? "-" . $containerName : ""))
            . " " . escapeshellarg($this->getId());
        return $command;
    }
}
