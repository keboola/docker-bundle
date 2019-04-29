<?php

namespace Keboola\DockerBundle\Tests;

use PHPUnit_Framework_BaseTestListener;
use PHPUnit_Framework_Test;

class SimpleTestListener extends PHPUnit_Framework_BaseTestListener
{
    public function endTest(PHPUnit_Framework_Test $test, $time)
    {
        printf(
            "Test '%s' ended in %s m %s s.\n",
            $test->getName(),
            round(floor($time / 60)),
            round($time - floor($time / 60))
        );
    }
}
