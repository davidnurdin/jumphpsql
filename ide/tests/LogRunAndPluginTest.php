<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Ide;
use JumPHP\Ide\Log\LogBuffer;
use JumPHP\Ide\Workspace\LocalWorkspace;
use PHPUnit\Framework\TestCase;

final class LogRunAndPluginTest extends TestCase
{
    public function testWhatARunPrintedAndHowItEndedReachTheLogsAsTheRunSource(): void
    {
        $dir = sys_get_temp_dir() . '/jph-logrun-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $ide = new Ide(new LocalWorkspace($dir), null, function (): void {
        }, new Processes());
        $ide->log = new LogBuffer();
        $logRun = new \ReflectionMethod(Ide::class, 'logRun');
        $logRun->invoke($ide, 'run.output', ['id' => 3, 'chunk' => "\e[32mHello\e[0m from the game\n"]);
        $logRun->invoke($ide, 'run.exit', ['id' => 3, 'code' => 1, 'seconds' => 2.5, 'label' => 'Run hello']);
        $entries = $ide->log->list(0, 'debug', 'run')['entries'] ?? $ide->log->list(0, 'debug', 'run');
        $entry = end($entries);
        self::assertSame('error', $entry['level']);
        self::assertSame('run', $entry['source']);
        self::assertStringContainsString('Run hello ended with code 1 after 2.5 s', $entry['message']);
        self::assertStringContainsString('Hello from the game', $entry['message']);      // the colour codes are gone
        self::assertStringNotContainsString("\e[", $entry['message']);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
