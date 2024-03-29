<?php

/*
 * This file is part of the Blackfire Player package.
 *
 * (c) Fabien Potencier <fabien@blackfire.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Blackfire\Player\Tests\Console;

use Blackfire\Player\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\SetUpTearDownTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class PlayerCommandTest extends TestCase
{
    use SetUpTearDownTrait;

    private static $port;
    private static $server;

    public static function doSetUpBeforeClass()
    {
        static::$port = getenv('BLACKFIRE_WS_PORT');

        self::bootServer();
    }

    public static function doTearDownAfterClass()
    {
        self::$server->stop(0);
        self::$server = null;
    }

    private static function bootServer()
    {
        if (self::$server && !self::$server->isTerminated() && self::$server->isRunning()) {
            return;
        }

        if (self::$server) {
            self::$server->stop(0);
        }

        $finder = new PhpExecutableFinder();

        if (false === $binary = $finder->find()) {
            throw new \RuntimeException('Unable to find PHP binary to run server.');
        }

        self::$server = new Process([$binary, '-S', '0:'.static::$port, '-t', __DIR__.'/../fixtures-run']);
        self::$server->start();

        usleep(250000);

        if (self::$server->isTerminated() && !self::$server->isSuccessful()) {
            throw new ProcessFailedException(self::$server);
        }
    }

    public function doSetUp()
    {
        self::bootServer();
    }

    public function providePlayerTests()
    {
        $dirs = Finder::create()
            ->in(__DIR__.'/../fixtures-run')
            ->directories();

        foreach ($dirs as $dir) {
            foreach (['index.php', 'output.txt', 'scenario.bkf'] as $file) {
                $file = sprintf('%s/%s', $dir->getPathname(), $file);
                if (!file_exists($file)) {
                    throw new \Exception(sprintf('The fixture file "%s" does not exist.', $file));
                }
            }

            $reportFile = sprintf('%s/output-full-report.txt', $dir->getPathname());

            yield $dir->getBasename() => [
                sprintf('%s/scenario.bkf', $dir->getPathname()),
                file_get_contents(sprintf('%s/output.txt', $dir->getPathname())),
                file_exists($reportFile) ? file_get_contents($reportFile) : null,
            ];
        }
    }

    /** @dataProvider providePlayerTests */
    public function testPlayer($file, $expectedOutput, $expectedReportOutput)
    {
        $application = new Application();
        $tester = new CommandTester($application->get('run'));
        $tester->execute([
            'file' => $file,
            '--endpoint' => 'http://0:'.static::$port,
        ]);

        $output = $tester->getDisplay();
        $output = implode("\n", array_map('rtrim', explode("\n", $output)));
        $expectedOutput = str_replace('{{ PORT }}', static::$port, $expectedOutput);

        $this->assertSame($expectedOutput, $output);

        // For --json or --full-report, the output is composed of STDOUT + STDERR.
        // That's because the CommandTester use a StreamOutput instead of a ConsoleOutput.

        if ($expectedReportOutput) {
            $tester->execute([
                'file' => $file,
                '--endpoint' => 'http://0:'.static::$port,
                '--json' => true,
            ]);

            $this->assertStringMatchesFormat($expectedReportOutput, $tester->getDisplay());
        }
    }

    public function testErrorStdIn()
    {
        $finder = new PhpExecutableFinder();
        $process = new Process([$finder->find(), 'blackfire-player.php', 'run', '--json'], __DIR__.'/../../../bin');
        $process->setInput('papilou!');
        $process->run();

        $expectedOutput = '{
    "message": "Unable to parse \"papilou!\" at line 1.",
    "success": false,
    "errors": [],
    "input": {
        "path": "php://stdin",
        "content": "papilou!"
    }
}
';

        $expectedErrorOutput = <<<EOD
  Unable to parse "papilou!" at line 1.
EOD;

        $this->assertSame($expectedOutput, $process->getOutput());
        $this->assertStringContainsString($expectedErrorOutput, $process->getErrorOutput());
    }

    public function testNoEndpoint()
    {
        $script = <<<EOS
scenario
    name "Test"
    visit "/"
        expect status_code() == 200
EOS;

        $finder = new PhpExecutableFinder();
        $process = new Process([$finder->find(), 'blackfire-player.php', 'run', '--json'], __DIR__.'/../../../bin');
        $process->setInput($script);
        $process->run();

        $expectedOutput = '{
    "name": null,
    "results": [
        {
            "scenario": "\"Test\"",
            "values": [],
            "error": {
                "message": "Unable to crawl a non-absolute URI (/). Did you forget to set an \"endpoint\"?",
                "code": 0
            }
        }
    ],
    "message": "Build encountered a fatal error",
    "code": 65,
    "success": true,
    "input": {
        "path": "php://stdin",
        "content": "scenario\n    name \"Test\"\n    visit \"/\"\n        expect status_code() == 200"
    }
}
';

        $this->assertSame($expectedOutput, $process->getOutput());
        $this->assertStringContainsString('Unable to crawl a non-absolute URI (/). Did you forget to set an "endpoint"?', $process->getErrorOutput());
    }
}
