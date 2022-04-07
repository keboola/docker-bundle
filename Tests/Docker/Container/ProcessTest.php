<?php

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Docker\Container\Process;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;

class ProcessTest extends TestCase
{
    private Temp $temp;

    public function setUp(): void
    {
        parent::setUp();
        $this->temp = new Temp();
        $this->temp->initRunFolder();
    }

    public function testOutputFilter(): void
    {
        $outputFilter = new OutputFilter();
        $outputFilter->addValue('boo');
        file_put_contents($this->temp->getTmpFolder() . '/run.php', <<<'PHP'
<?php

fwrite(STDOUT, 'pho boo foo' . PHP_EOL);
fwrite(STDERR, 'foo boo bar' . PHP_EOL);
PHP
        );
        $process = new Process('php ' . $this->temp->getTmpFolder() . '/run.php');
        $process->run();
        $process->setOutputFilter($outputFilter);
        self::assertSame('pho [hidden] foo', $process->getOutput());
        self::assertSame('foo [hidden] bar', $process->getErrorOutput());
    }

    public function testOutputFilterWithCallback(): void
    {
        $outputFilter = new OutputFilter();
        $outputFilter->addValue('boo');
        file_put_contents($this->temp->getTmpFolder() . '/run.php', <<<'PHP'
<?php

fwrite(STDOUT, 'pho boo foo' . PHP_EOL);
fwrite(STDERR, 'foo boo bar' . PHP_EOL);
PHP
        );
        $process = new Process('php ' . $this->temp->getTmpFolder() . '/run.php');
        $events = [];
        $process->setOutputFilter($outputFilter);
        $process->run(function ($type, $e) use (&$events) {
            return $events[$type][] = $e;
        });
        self::assertEquals(
            [
                'out' => [
                    'pho [hidden] foo',
                ],
                'err' => [
                    'foo [hidden] bar',
                ],
            ],
            $events
        );
        self::assertSame('pho [hidden] foo', $process->getOutput());
        self::assertSame('foo [hidden] bar', $process->getErrorOutput());
    }

    public function testFilterBrokenUnicode(): void
    {
        file_put_contents($this->temp->getTmpFolder() . '/run.php', <<<'PHP'
<?php

fwrite(STDOUT, substr('a😀b', 0, 3) . PHP_EOL);
fwrite(STDERR, substr('b😀c', 0, 3) . PHP_EOL);
PHP
        );
        $process = new Process('php ' . $this->temp->getTmpFolder() . '/run.php');
        $process->run();
        self::assertSame('a', $process->getOutput());
        self::assertSame('b', $process->getErrorOutput());
    }

    public function testFilterBrokenUnicodeWithCallback(): void
    {
        file_put_contents($this->temp->getTmpFolder() . '/run.php', <<<'PHP'
<?php

fwrite(STDOUT, substr('a😀b', 0, 3) . PHP_EOL);
fwrite(STDERR, substr('b😀c', 0, 3) . PHP_EOL);
PHP
        );
        $events = [];
        $process = new Process('php ' . $this->temp->getTmpFolder() . '/run.php');
        $process->run(function ($type, $e) use (&$events) {
            return $events[$type][] = $e;
        });
        self::assertEquals(
            [
                'out' => [
                    'a',
                ],
                'err' => [
                    'b',
                ],
            ],
            $events
        );
        self::assertSame('a', $process->getOutput());
        self::assertSame('b', $process->getErrorOutput());
    }

    public function testFilterLargeOutput(): void
    {
        var_dump(memory_get_peak_usage(true));
        ini_set('memory_limit', (memory_get_peak_usage(true) / (10**6)) + 100 . 'm');

        file_put_contents($this->temp->getTmpFolder() . '/run.php', <<<'PHP'
<?php

fwrite(STDOUT, 'a' . str_repeat(substr('😀', 0, 1), 50*(10**6)) . PHP_EOL);
fwrite(STDERR, 'b' . str_repeat(substr('😀', 0, 1), 50*(10**6)) . PHP_EOL);
PHP
        );
        $process = new Process('php ' . $this->temp->getTmpFolder() . '/run.php');
        $process->run();
        var_dump(memory_get_peak_usage(true));
        self::assertSame('a [trimmed]', $process->getOutput());
        self::assertSame('b [trimmed]', $process->getErrorOutput());
    }

    public function testFilterLargeOutputCallback(): void
    {
        var_dump(memory_get_peak_usage(true));
        ini_set('memory_limit', (memory_get_peak_usage(true) / (10**6)) + 100 . 'm');
        file_put_contents($this->temp->getTmpFolder() . '/run.php', <<<'PHP'
<?php

fwrite(STDOUT, 'a' . str_repeat(substr('😀', 0, 1), 50*(10**6)) . PHP_EOL);
fwrite(STDERR, 'b' . str_repeat(substr('😀', 0, 1), 50*(10**6)) . PHP_EOL);
PHP
        );
        $process = new Process('php ' . $this->temp->getTmpFolder() . '/run.php');
        $events = [];
        $process->run(function ($type, $e) use (&$events) {
            return $events[$type][] = $e;
        });
        var_dump(memory_get_peak_usage(true));
        // there can be any number of `[trimmed]` events depending on how the garbage is broken into buffers,
        // which depends on system mood
        self::assertGreaterThanOrEqual(1, count($events['out']));
        self::assertGreaterThanOrEqual(1, count($events['err']));
        self::assertStringStartsWith('a', $events['out'][0]);
        self::assertStringStartsWith('b', $events['err'][0]);
        self::assertSame('a [trimmed]', $process->getOutput());
        self::assertSame('b [trimmed]', $process->getErrorOutput());
    }
}
