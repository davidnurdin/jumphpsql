<?php

namespace JumPHP\Ide\Claude;

/**
 * What each AI did, kept in the project, so that an AI (or a person) can walk through the whole folder and know the history:
 *
 *   IA/HISTORY/INDEX.md                                      one line per turn, in order (id, date, assistant, model, request, files)
 *   IA/HISTORY/.seq                                          the last turn number (they only grow: 0001, 0002...)
 *   IA/HISTORY/<CLAUDE|OPEN CODE>/<model>/<dd-mm-YYYY HH-MM-SS>/
 *       <id>-<request>.md           the messages (every request and answer with its time) and a summary of the actions
 *       <id>-<request>.actions.md   every command / tool the AI ran, one numbered block each (<id>-001, <id>-002...): time, what,
 *                                   its result (the end of it when long) and how it ended
 *
 * Watches the events the Claude window gets (claude.event); written when a turn ends. The folder is NEVER sent to git (IA/ is added to
 * .gitignore): it lives on this computer only, and is lost with the folder.
 */
final class AiHistory
{
    /** a result longer than this is cut (its end is kept: that is where the error is) */
    public const RESULT_MAX = 6000;

    /** @var array<string, array<string, mixed>> conversation => the turn in progress */
    private array $turns = [];

    public function __construct(private string $root)
    {
    }

    /** A message sent to an assistant: it starts the turn, or joins the one in progress. */
    public function sent(string $conv, string $agent, ?string $model, string $prompt): void
    {
        if (!isset($this->turns[$conv])) {
            $this->turns[$conv] = ['agent' => $agent, 'model' => $model, 'started' => microtime(true), 'messages' => [], 'actions' => [], 'uses' => []];
        }
        $this->turns[$conv]['messages'][] = ['time' => microtime(true), 'role' => 'user', 'text' => $prompt];
    }

    /** @param array<string, mixed> $event a stream-json event (Claude Code's, or OpenCode's told in the same words) @return string|null the file written */
    public function observe(string $conv, array $event): ?string
    {
        if (!isset($this->turns[$conv])) {
            return null;
        }
        $turn = &$this->turns[$conv];
        switch ($event['type'] ?? '') {
            case 'system':
                if (is_string($event['model'] ?? null) && $event['model'] !== '' && $event['model'] !== 'OpenCode') {
                    $turn['model'] = $event['model'];
                }
                break;
            case 'assistant':
                foreach ((array)($event['message']['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'text' && trim((string)$part['text']) !== '') {
                        $turn['messages'][] = ['time' => microtime(true), 'role' => 'assistant', 'text' => trim((string)$part['text'])];
                    } elseif (($part['type'] ?? '') === 'tool_use') {
                        $turn['actions'][] = ['time' => microtime(true), 'tool' => (string)($part['name'] ?? '?'), 'input' => (array)($part['input'] ?? []),
                            'result' => null, 'failed' => false, 'ended' => null];
                        $turn['uses'][(string)($part['id'] ?? '')] = array_key_last($turn['actions']);
                    }
                }
                break;
            case 'user':     // the results of the tools it ran
                foreach ((array)($event['message']['content'] ?? []) as $part) {
                    $n = $turn['uses'][(string)($part['tool_use_id'] ?? '')] ?? null;
                    if (($part['type'] ?? '') === 'tool_result' && $n !== null) {
                        $turn['actions'][$n]['result'] = self::text($part['content'] ?? '');
                        $turn['actions'][$n]['failed'] = !empty($part['is_error']);
                        $turn['actions'][$n]['ended'] = microtime(true);
                    }
                }
                break;
            case 'result':
                $done = $turn;
                unset($this->turns[$conv]);
                return $this->write($done, $event);
        }
        return null;
    }

    /** The text of a tool result (a string, or a list of {type: text, text}). */
    private static function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $out = [];
        foreach ((array)$content as $part) {
            $out[] = is_array($part) ? (string)($part['text'] ?? json_encode($part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : (string)$part;
        }
        return implode("\n", $out);
    }

    private static function when(float $t): string
    {
        return date('Y-m-d H:i:s', (int)$t) . substr(sprintf('%.3f', fmod($t, 1)), 1);
    }

    /** What one action is, in one line (a command, a file, a pattern, an address...). */
    private static function what(array $input): string
    {
        $one = (string)($input['command'] ?? $input['file_path'] ?? $input['path'] ?? $input['pattern'] ?? $input['url'] ?? $input['query'] ?? '');
        return $one !== '' ? $one : (string)json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $turn @param array<string, mixed> $result @return string|null the .md written (path from the project root) */
    private function write(array $turn, array $result): ?string
    {
        $agent = $turn['agent'] === 'opencode' ? 'OPEN CODE' : 'CLAUDE';
        $model = preg_replace('/[^A-Za-z0-9._\[\]-]+/', '-', (string)($turn['model'] ?? '')) ?: 'default';
        $date = date('d-m-Y H-i-s', (int)$turn['started']);     // no ":" (not allowed in Windows file names)
        $first = (string)($turn['messages'][0]['text'] ?? 'turn');
        $slug = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(mb_substr(trim($first), 0, 50))), '-') ?: 'turn';
        $base = "$this->root/IA/HISTORY";
        $dir = "$base/$agent/$model/$date";
        if (!@is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }
        $id = $this->nextId($base);
        $name = "$id-$slug";
        $end = microtime(true);
        $ok = ($result['is_error'] ?? false) ? 'error' : 'ok';

        $md = "# $id · $slug\n\n- Turn: $id\n- Assistant: $agent\n- Model: " . ($turn['model'] ?? 'default') . "\n- Started: " . self::when($turn['started'])
            . "\n- Ended: " . self::when($end) . "\n- Duration: " . round((float)($result['duration_ms'] ?? ($end - $turn['started']) * 1000) / 1000, 1) . " s\n- Result: $ok\n"
            . '- Actions: ' . count($turn['actions']) . ($turn['actions'] ? " (details with their results: $name.actions.md)" : '') . "\n\n## Conversation\n";
        foreach ($turn['messages'] as $m) {
            $md .= "\n### " . self::when($m['time']) . ' · ' . ($m['role'] === 'user' ? 'User' : $agent) . "\n\n" . $m['text'] . "\n";
        }
        if ($turn['actions']) {
            $md .= "\n## Actions (the order they were run in)\n\n";
            foreach ($turn['actions'] as $i => $a) {
                $md .= sprintf("- %s-%03d · %s · %s %s%s\n", $id, $i + 1, self::when($a['time']), $a['tool'], mb_substr(preg_replace('/\s+/', ' ', self::what($a['input'])) ?? '', 0, 140),
                    $a['failed'] ? ' · FAILED' : '');
            }
        }
        file_put_contents("$dir/$name.md", $md);

        if ($turn['actions']) {
            $log = "# $id · actions of {$slug}\n\n- Turn: $id ($agent, " . ($turn['model'] ?? 'default') . ", started " . self::when($turn['started']) . ")\n- Messages: $name.md\n";
            foreach ($turn['actions'] as $i => $a) {
                $res = (string)($a['result'] ?? '');
                $cut = mb_strlen($res) > self::RESULT_MAX;
                $log .= sprintf("\n---\n\n## %s-%03d · %s\n\n- Tool: %s\n- Started: %s\n- Ended: %s\n- Outcome: %s\n", $id, $i + 1, $a['tool'], $a['tool'], self::when($a['time']),
                    $a['ended'] !== null ? self::when($a['ended']) : '(no result received)', $a['failed'] ? 'failed' : ($a['ended'] !== null ? 'ok' : 'unknown'));
                $input = $a['input'];
                if (isset($input['command'])) {
                    $log .= "\n**Command**\n\n```\n" . $input['command'] . "\n```\n";
                    unset($input['command']);
                }
                if ($input) {
                    $log .= "\n**Input**\n\n```json\n" . json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n```\n";
                }
                $log .= "\n**Result**" . ($cut ? ' (the last ' . self::RESULT_MAX . ' characters of ' . mb_strlen($res) . ')' : '') . "\n\n```\n"
                    . ($res === '' ? '(empty)' : ($cut ? '…' . mb_substr($res, -self::RESULT_MAX) : $res)) . "\n```\n";
            }
            file_put_contents("$dir/$name.actions.md", $log);
        }

        $index = "$base/INDEX.md";
        if (!is_file($index)) {
            file_put_contents($index, "# AI history of this project\n\nOne line per turn, oldest first. A turn's files: IA/HISTORY/<assistant>/<model>/<date>/<id>-<request>.md (the conversation) and "
                . ".actions.md (every command / tool with its result). Local to this computer, never in git.\n\n| Turn | Started | Assistant | Model | Actions | Result | Request | Folder |\n|---|---|---|---|---|---|---|---|\n");
        }
        file_put_contents($index, sprintf("| %s | %s | %s | %s | %d | %s | %s | `%s` |\n", $id, self::when($turn['started']), $agent, $turn['model'] ?? 'default', count($turn['actions']), $ok,
            str_replace('|', '/', mb_substr(preg_replace('/\s+/', ' ', trim($first)) ?? '', 0, 80)), substr($dir, strlen($this->root) + 1)), FILE_APPEND | LOCK_EX);
        $this->ignore();
        return substr("$dir/$name.md", strlen($this->root) + 1);
    }

    /** The next turn number, 4 digits, kept in IA/HISTORY/.seq (they only grow, over every assistant and model). */
    private function nextId(string $base): string
    {
        $file = "$base/.seq";
        $h = @fopen($file, 'c+');
        if ($h === false) {
            return '0000';
        }
        flock($h, LOCK_EX);
        $n = (int)trim((string)stream_get_contents($h)) + 1;
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, (string)$n);
        flock($h, LOCK_UN);
        fclose($h);
        return sprintf('%04d', $n);
    }

    /** IA/ stays out of git: this history is private to the computer. */
    private function ignore(): void
    {
        $file = "$this->root/.gitignore";
        $text = is_file($file) ? (string)file_get_contents($file) : '';
        if (!preg_match('#^/?IA/?\s*$#m', $text)) {
            file_put_contents($file, rtrim($text, "\n") . ($text === '' ? '' : "\n") . "# what the AI assistants did (the IDE's Claude / OpenCode): private to this computer, never in git\n/IA/\n");
        }
    }
}
