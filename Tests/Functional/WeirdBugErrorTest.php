<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

class WeirdBugErrorTest extends \PHPUnit_Framework_TestCase
{
    public function testContainerHandler()
    {
        $tag = '1.1.1';
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo-app",
                "tag" => $tag
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
        ];
        $log = new Logger("null");
        $handler1 = new TestHandler();
        $log->pushHandler($handler1);
        $logContainer = new ContainerLogger("null");
        $handler2 = new TestHandler();
        $logContainer->pushHandler($handler2);
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        // Create a stub for the SomeClass class.
        $container = $this->getMockBuilder(Container::class)
            ->setConstructorArgs([$image, $log, $logContainer])
            ->setMethods(['getRunCommand'])
            ->getMock();

        $temp = new Temp();
        $root = $temp->getTmpFolder();
        $containerId = 'docker-test57992dc6cefbf';
        $container->method('getRunCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "failed: (125) docker: Error response from daemon: open /dev/mapper/docker-202:1-283379-999e9139632af567c234d87fecd9f08c01834303e83dfcfe758a62db66932182: no such file or directory." && exit 125\'',
                'sudo timeout --signal=SIGKILL 60 docker run --volume="' . $root . '/data/":/data --memory="64m" --memory-swap="64m" --cpu-shares="1024" --net="bridge" --name="'. $containerId . '" "keboola/docker-demo-app:' . $tag . '"'
            ));

        /** @var Container $container */
        $container->createDataDir($root);
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-main.data.csv',
                        ]
                    ]
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'out.c-main.data',
                        ]
                    ]
                ],
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => 10,
            ]
        ];
        file_put_contents($root . "/data/config.json", json_encode($config));
        $dataFile = <<< EOF
id,text,some_other_column
1,"Short text","Whatever"
2,"Long text Long text Long text","Something else"
EOF;
        file_put_contents($root . "/data/in/tables/in.c-main.data.csv", $dataFile);

        $container->run($containerId, []);
        $this->assertTrue($handler1->hasErrorThatContains('Phantom of the opera'));
        $this->assertTrue($handler2->hasInfoThatContains('Processed 2 rows.'));
    }


    public function testContainerHandlerTerminate()
    {
        $tag = '1.1.1';
        $imageConfig = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo-app",
                "tag" => $tag
            ],
            "cpu_shares" => 1024,
            "memory" => "64m",
            "configuration_format" => "json",
        ];
        $log = new Logger("null");
        $handler1 = new TestHandler();
        $log->pushHandler($handler1);
        $logContainer = new ContainerLogger("null");
        $handler2 = new TestHandler();
        $logContainer->pushHandler($handler2);
        $encryptor = new ObjectEncryptor();
        $image = Image::factory($encryptor, $log, $imageConfig);

        // Create a stub for the SomeClass class.
        $container = $this->getMockBuilder(Container::class)
            ->setConstructorArgs([$image, $log, $logContainer])
            ->setMethods(['getRunCommand'])
            ->getMock();

        $temp = new Temp();
        $root = $temp->getTmpFolder();
        $containerId = 'docker-test57992dc6cefbf';
        $container->method('getRunCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "failed: (125) docker: Error response from daemon: open /dev/mapper/docker-202:1-283379-999e9139632af567c234d87fecd9f08c01834303e83dfcfe758a62db66932182: no such file or directory." && exit 125\'',
                'sh -c -e \'echo "failed: (125) docker: Error response from daemon: open /dev/mapper/docker-202:1-283379-999e9139632af567c234d87fecd9f08c01834303e83dfcfe758a62db66932182: no such file or directory." && exit 125\'',
                'sh -c -e \'echo "failed: (125) docker: Error response from daemon: open /dev/mapper/docker-202:1-283379-999e9139632af567c234d87fecd9f08c01834303e83dfcfe758a62db66932182: no such file or directory." && exit 125\'',
                'sh -c -e \'echo "failed: (125) docker: Error response from daemon: open /dev/mapper/docker-202:1-283379-999e9139632af567c234d87fecd9f08c01834303e83dfcfe758a62db66932182: no such file or directory." && exit 125\'',
                'sh -c -e \'echo "failed: (125) docker: Error response from daemon: open /dev/mapper/docker-202:1-283379-999e9139632af567c234d87fecd9f08c01834303e83dfcfe758a62db66932182: no such file or directory." && exit 125\'',
                'sh -c -e \'echo "failed: (125) docker: Error response from daemon: open /dev/mapper/docker-202:1-283379-999e9139632af567c234d87fecd9f08c01834303e83dfcfe758a62db66932182: no such file or directory." && exit 125\'',
                'sudo timeout --signal=SIGKILL 60 docker run --volume="' . $root . '/data/":/data --memory="64m" --memory-swap="64m" --cpu-shares="1024" --net="bridge" --name="'. $containerId . '" "keboola/docker-demo-app:' . $tag . '"'
            ));

        /** @var Container $container */
        $container->createDataDir($root);
        $config = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-main.data.csv',
                        ]
                    ]
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'sliced.csv',
                            'destination' => 'out.c-main.data',
                        ]
                    ]
                ],
            ],
            'parameters' => [
                'primary_key_column' => 'id',
                'data_column' => 'text',
                'string_length' => 10,
            ]
        ];
        file_put_contents($root . "/data/config.json", json_encode($config));
        $dataFile = <<< EOF
id,text,some_other_column
1,"Short text","Whatever"
2,"Long text Long text Long text","Something else"
EOF;
        file_put_contents($root . "/data/in/tables/in.c-main.data.csv", $dataFile);

        try {
            $container->run($containerId, []);
            $this->fail("Too many errors must fail");
        } catch (ApplicationException $e) {
        }
    }
}
