<?php

namespace JumPHP\Ide\Claude;

use JumPHP\Ide\Async\Environment;

/**
 * "Open in a Terminal": the conversation goes on in the desktop's own terminal, with the standard `claude` (or
 * `opencode`) program, in the project's folder, resuming the conversation's session when it has one.
 */
final class ExternalTerminal
{
    /** [program, arguments before the command] — the first one found is used (the system's default first) */
    private const TERMINALS = [
        ['x-terminal-emulator', ['-e']],
        ['gnome-terminal', ['--']],
        ['kgx', ['-e']],
        ['konsole', ['-e']],
        ['xfce4-terminal', ['-x']],
        ['mate-terminal', ['-x']],
        ['tilix', ['-e']],
        ['kitty', []],
        ['alacritty', ['-e']],
        ['wezterm', ['start', '--']],
        ['xterm', ['-e']],
    ];

    public function __construct(private string $root)
    {
    }

    /**
     * The program and arguments of the conversation, as `claude` / `opencode` understand them.
     *
     * @return list<string>
     */
    public static function agentCommand(string $agent, ?string $session, ?string $model, string $mode = 'acceptEdits'): array
    {
        if ($agent === 'opencode') {
            $command = ['opencode'];
            if ($session !== null && preg_match('/^ses_[A-Za-z0-9]{8,64}$/', $session)) {
                array_push($command, '--session', $session);
            }
            if ($model !== null && preg_match('#^[A-Za-z0-9._:/@-]{1,100}$#', $model)) {
                array_push($command, '--model', $model);
            }
            return $command;
        }
        $command = ['claude'];
        if ($session !== null && preg_match('/^[\w-]{8,64}$/', $session)) {
            array_push($command, '--resume', $session);
        }
        if ($model !== null && preg_match(ClaudeCode::MODEL, $model)) {
            array_push($command, '--model', $model);
        }
        if (in_array($mode, ClaudeCode::MODES, true)) {
            array_push($command, '--permission-mode', $mode);
        }
        return $command;
    }

    /** @return array{terminal: string} */
    public function open(string $agent, ?string $session, ?string $model, string $mode = 'acceptEdits'): array
    {
        $agent = $agent === 'opencode' ? 'opencode' : 'claude';
        if (Environment::which($agent) === null) {
            throw new \RuntimeException("`$agent` was not found on this computer: install it first.");
        }
        $command = self::agentCommand($agent, $session, $model, $mode);
        $line = implode(' ', array_map('escapeshellarg', $command));
        // the terminal stays open when the program ends (a shell takes over)
        $script = 'cd ' . escapeshellarg($this->root) . ' && ' . $line . '; exec "${SHELL:-/bin/bash}"';

        if (PHP_OS_FAMILY === 'Darwin') {
            $apple = 'tell application "Terminal" to do script ' . json_encode($script) . "\ntell application \"Terminal\" to activate";
            $this->spawn('osascript -e ' . escapeshellarg($apple));
            return ['terminal' => 'Terminal'];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $win = 'cd /d "' . $this->root . '" && ' . implode(' ', $command);
            $this->spawn('start "" cmd /k ' . escapeshellarg($win));
            return ['terminal' => 'cmd'];
        }
        foreach (self::TERMINALS as [$program, $args]) {
            if (($found = Environment::which($program)) === null) {
                continue;
            }
            $full = escapeshellarg($found) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' bash -c ' . escapeshellarg($script);
            $this->spawn($full);
            return ['terminal' => $program];
        }
        throw new \RuntimeException('No terminal found (x-terminal-emulator, gnome-terminal, konsole, xfce4-terminal, kitty, alacritty, xterm...).');
    }

    private function spawn(string $command): void
    {
        $env = Environment::variables();
        $process = @proc_open(PHP_OS_FAMILY === 'Windows' ? $command : 'setsid ' . $command . ' >/dev/null 2>&1 &', [['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes, $this->root, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the terminal.');
        }
        proc_close($process);
    }
}
