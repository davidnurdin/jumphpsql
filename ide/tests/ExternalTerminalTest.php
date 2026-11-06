<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Claude\ExternalTerminal;
use PHPUnit\Framework\TestCase;

final class ExternalTerminalTest extends TestCase
{
    public function testClaudeResumesTheSession(): void
    {
        self::assertSame(['claude', '--resume', 'abc-12345678', '--model', 'sonnet', '--permission-mode', 'plan'],
            ExternalTerminal::agentCommand('claude', 'abc-12345678', 'sonnet', 'plan'));
    }

    public function testOpenCodeResumesTheSessionAndIgnoresABadOne(): void
    {
        self::assertSame(['opencode', '--session', 'ses_abcdefgh12'], ExternalTerminal::agentCommand('opencode', 'ses_abcdefgh12', null));
        self::assertSame(['opencode'], ExternalTerminal::agentCommand('opencode', 'x; rm -rf', null));
    }
}
