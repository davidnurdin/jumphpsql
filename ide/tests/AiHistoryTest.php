<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Claude\AiHistory;
use JumPHP\Ide\Claude\OpenCode;
use JumPHP\Ide\Run\ToolInstaller;
use PHPUnit\Framework\TestCase;

final class AiHistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jph-ia-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testATurnIsWrittenUnderIaHistoryWithItsCommandsAndResultsInAnotherFile(): void
    {
        $history = new AiHistory($this->dir);
        $history->sent('c1', 'claude', 'claude-opus-5-5', 'Fix the jump bug');
        $history->observe('c1', ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Let me run the tests.'],
            ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'Bash', 'input' => ['command' => 'bin/jumphp test hero', 'description' => 'tests']]]]]);
        $history->observe('c1', ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'tu1', 'content' => [['type' => 'text', 'text' => "FAIL 2 tests\nboom"]], 'is_error' => true]]]]);
        $history->observe('c1', ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 'tu2', 'name' => 'Edit', 'input' => ['file_path' => 'src/Hero.php']]]]]);
        $history->observe('c1', ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'tu2', 'content' => 'edited']]]]);
        $file = $history->observe('c1', ['type' => 'result', 'duration_ms' => 1500]);
        self::assertMatchesRegularExpression('#^IA/HISTORY/CLAUDE/claude-opus-5-5/\d\d-\d\d-\d{4} \d\d-\d\d-\d\d/0001-fix-the-jump-bug\.md$#', (string)$file);
        $md = (string)file_get_contents("$this->dir/$file");
        self::assertStringContainsString('Fix the jump bug', $md);
        self::assertStringContainsString('Let me run the tests.', $md);
        self::assertMatchesRegularExpression('/0001-001 · \d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3} · Bash bin\/jumphp test hero · FAILED/', $md);
        $actions = (string)file_get_contents("$this->dir/" . str_replace('.md', '.actions.md', $file));
        self::assertStringContainsString("## 0001-001 · Bash", $actions);
        self::assertStringContainsString("bin/jumphp test hero", $actions);
        self::assertStringContainsString("FAIL 2 tests\nboom", $actions);          // the result of the command
        self::assertStringContainsString('Outcome: failed', $actions);
        self::assertStringContainsString("## 0001-002 · Edit", $actions);
        self::assertStringContainsString('/IA/', (string)file_get_contents("$this->dir/.gitignore"));
        // a second turn: the number grows, the index lists both
        $history->sent('c1', 'claude', 'claude-opus-5-5', 'Next thing');
        $second = $history->observe('c1', ['type' => 'result']);
        self::assertStringContainsString('/0002-next-thing.md', '/' . substr((string)$second, strrpos((string)$second, '/') + 1));
        $index = (string)file_get_contents("$this->dir/IA/HISTORY/INDEX.md");
        self::assertStringContainsString('| 0001 |', $index);
        self::assertStringContainsString('| 0002 |', $index);
        self::assertSame('2', trim((string)file_get_contents("$this->dir/IA/HISTORY/.seq")));
    }

    public function testOpenCodeHasItsOwnFolderAndItsPermissionsFollowTheMode(): void
    {
        $history = new AiHistory($this->dir);
        $history->sent('c2', 'opencode', null, 'Hello');
        self::assertStringStartsWith('IA/HISTORY/OPEN CODE/default/', (string)$history->observe('c2', ['type' => 'result']));
        self::assertSame('{"edit":"deny","bash":"deny"}', OpenCode::permissions('plan'));
        self::assertStringContainsString('"external_directory":"allow"', OpenCode::permissions('auto'));
    }

    public function testEveryKnownProgramHasADownloadPageWhenItCannotBeInstalledHere(): void
    {
        foreach (ToolInstaller::tools() as $tool) {
            self::assertStringStartsWith('https://', (new ToolInstaller(new \JumPHP\Ide\Async\Processes(), $this->dir))->plan($tool)['page']);
        }
    }
}
