<?php

namespace PluggedHost\FiveM\Services;

/**
 * A server.cfg that can be read as settings and written back without disturbing anything else.
 *
 * FXServer executes the file top to bottom as console commands: `sv_hostname "x"`,
 * `sets sv_projectName "x"`, `ensure chat`, `add_ace group.admin command allow`. A form over it
 * has to find "the" hostname among those lines, change one token of one line, and leave the
 * comments, blank lines and unknown commands around it exactly as they were. So nothing here
 * re-renders the file: every change is an operation on a line of the file as it was parsed
 * (replace, delete, insert before or after), and render() applies them in one pass. Line numbers
 * therefore always mean the original file, which is what lets the page hand list rows a key
 * ("12" = line 12) and match them back on save.
 *
 * The tokenizer follows the cfg grammar FXServer uses: whitespace-separated tokens, double
 * quotes group a token, and a token that starts with `#` or `//` starts a comment. A `#` or `//`
 * inside a token is part of it, which is what keeps an unquoted URL a URL.
 */
final class ServerCfg
{
    private const SET = ['set', 'sets', 'setr', 'seta'];

    /** Commands a new convar line goes in front of, so it is set before resources start. */
    private const LOADS = ['ensure', 'start', 'restart', 'exec'];

    public const NAME = '/^[\w\-.@\[\]]+$/';

    public const PRINCIPAL = '/^[\w\-.:@\[\]]+$/';

    /** @var array<int, string> */
    private array $lines;

    private string $eol;

    private bool $finalNewline;

    /** @var array<int, string> */
    private array $replace = [];

    /** @var array<int, true> */
    private array $delete = [];

    /** @var array<int, array<int, string>> */
    private array $before = [];

    /** @var array<int, array<int, string>> */
    private array $after = [];

    /** @var array<int, string> */
    private array $append = [];

    public function __construct(string $raw)
    {
        $this->eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $this->finalNewline = $raw === '' || str_ends_with($raw, "\n");

        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];

        if ($this->finalNewline && end($lines) === '') {
            array_pop($lines);
        }

        $this->lines = $lines;
    }

    public function render(): string
    {
        $out = [];

        foreach ($this->lines as $index => $line) {
            array_push($out, ...($this->before[$index] ?? []));

            if (!isset($this->delete[$index])) {
                $out[] = $this->replace[$index] ?? $line;
            }

            array_push($out, ...($this->after[$index] ?? []));
        }

        array_push($out, ...$this->append);

        return $out === [] ? '' : implode($this->eol, $out) . ($this->finalNewline ? $this->eol : '');
    }

    /**
     * A line's tokens with their offsets, and where its comment starts (null for none).
     *
     * @return array{0: array<int, array{text: string, start: int, end: int, quoted: bool}>, 1: ?int}
     */
    public static function tokenize(string $line): array
    {
        $tokens = [];
        $length = strlen($line);
        $i = 0;

        while ($i < $length) {
            $char = $line[$i];

            if ($char === ' ' || $char === "\t") {
                $i++;

                continue;
            }

            if ($char === '#' || ($char === '/' && ($line[$i + 1] ?? '') === '/')) {
                return [$tokens, $i];
            }

            if ($char === '"') {
                $close = strpos($line, '"', $i + 1);
                $end = $close === false ? $length : $close + 1;
                $tokens[] = ['text' => substr($line, $i + 1, ($close === false ? $length : $close) - $i - 1), 'start' => $i, 'end' => $end, 'quoted' => true];
                $i = $end;

                continue;
            }

            $start = $i;

            while ($i < $length && $line[$i] !== ' ' && $line[$i] !== "\t" && $line[$i] !== '"') {
                $i++;
            }

            $tokens[] = ['text' => substr($line, $start, $i - $start), 'start' => $start, 'end' => $i, 'quoted' => false];
        }

        return [$tokens, null];
    }

    /**
     * @return array<int, string>
     */
    private static function words(string $line): array
    {
        return array_column(self::tokenize($line)[0], 'text');
    }

    // --- convars -----------------------------------------------------------------------------

    /**
     * The value the file leaves a convar (or a one-argument command like load_server_icon) at:
     * the last active line that sets it wins, as it does when FXServer runs the file.
     */
    public function get(string $name): ?string
    {
        $found = $this->findSetting($name);

        if ($found === null) {
            return null;
        }

        return self::tokenize($this->lines[$found[0]])[0][$found[1]]['text'] ?? '';
    }

    /**
     * Change a setting's value in place, add it when the file does not have it, or drop it when
     * the value is empty.
     *
     * @param  'command'|'set'|'sets'|'setr'  $style  how a new line is written: `name value` or `set name value`
     * @param  bool  $quote  quote a new value (numbers read better bare)
     */
    public function set(string $name, ?string $value, string $style = 'set', bool $quote = true): void
    {
        $value = self::clean((string) $value);

        if ($value === '') {
            foreach ($this->settingLines($name) as $index) {
                $this->delete[$index] = true;
            }

            return;
        }

        $found = $this->findSetting($name);

        if ($found === null) {
            $line = ($style === 'command' ? '' : $style . ' ') . $name . ' ' . self::format($value, $quote);
            $anchor = $this->firstLoad();

            if ($anchor === null) {
                $this->append[] = $line;
            } else {
                $this->before[$anchor][] = $line;
            }

            return;
        }

        [$index, $position] = $found;
        $line = $this->lines[$index];
        $tokens = self::tokenize($line)[0];

        if (!isset($tokens[$position])) {
            $this->replace[$index] = rtrim($line) . ' ' . self::format($value, $quote);

            return;
        }

        $token = $tokens[$position];

        if ($token['text'] === $value) {
            return;
        }

        $this->replace[$index] = substr($line, 0, $token['start'])
            . self::format($value, $token['quoted'] || $quote)
            . substr($line, $token['end']);
    }

    /**
     * @return array{0: int, 1: int}|null the line and the token position of the value
     */
    private function findSetting(string $name): ?array
    {
        $found = null;

        foreach ($this->lines as $index => $line) {
            $position = self::settingPosition(self::words($line), $name);

            if ($position !== null) {
                $found = [$index, $position];
            }
        }

        return $found;
    }

    /**
     * @return array<int, int>
     */
    private function settingLines(string $name): array
    {
        $lines = [];

        foreach ($this->lines as $index => $line) {
            if (self::settingPosition(self::words($line), $name) !== null) {
                $lines[] = $index;
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $words
     */
    private static function settingPosition(array $words, string $name): ?int
    {
        if ($words === []) {
            return null;
        }

        $command = strtolower($words[0]);

        if ($command === strtolower($name)) {
            return 1;
        }

        if (in_array($command, self::SET, true) && isset($words[1]) && strcasecmp($words[1], $name) === 0) {
            return 2;
        }

        return null;
    }

    private function firstLoad(): ?int
    {
        foreach ($this->lines as $index => $line) {
            $words = self::words($line);

            if ($words !== [] && in_array(strtolower($words[0]), self::LOADS, true)) {
                return $index;
            }
        }

        return null;
    }

    // --- endpoints ---------------------------------------------------------------------------

    /**
     * The endpoint_add_tcp/udp addresses ("0.0.0.0:30120").
     *
     * @return array<int, string>
     */
    public function endpoints(): array
    {
        $endpoints = [];

        foreach ($this->lines as $line) {
            $words = self::words($line);

            if (isset($words[1]) && in_array(strtolower($words[0]), ['endpoint_add_tcp', 'endpoint_add_udp'], true)) {
                $endpoints[] = $words[1];
            }
        }

        return array_values(array_unique($endpoints));
    }

    // --- resources ---------------------------------------------------------------------------

    /**
     * Every `ensure`/`start` line, commented-out ones as disabled, in file order.
     *
     * A commented line only counts when it is exactly one such command (`# ensure chat`), so
     * prose that happens to start with "start" stays prose.
     *
     * @return array<int, array{key: string, name: string, enabled: bool}>
     */
    public function resources(): array
    {
        $rows = [];

        foreach ($this->lines as $index => $line) {
            $resource = self::resourceLine($line);

            if ($resource !== null) {
                $rows[] = ['key' => (string) $index, 'name' => $resource['name'], 'enabled' => $resource['enabled']];
            }
        }

        return $rows;
    }

    /**
     * @return array{name: string, enabled: bool, command: string, indent: string, comment: string}|null
     */
    private static function resourceLine(string $line): ?array
    {
        $indent = (string) preg_replace('/^(\s*).*$/s', '$1', $line);
        $body = ltrim($line);
        $enabled = true;

        if (preg_match('/^(#+|\/\/)\s*(.*)$/s', $body, $match) === 1) {
            $enabled = false;
            $body = $match[2];
        }

        [$tokens, $comment] = self::tokenize($body);

        if (count($tokens) !== 2 || !in_array(strtolower($tokens[0]['text']), ['ensure', 'start'], true)) {
            return null;
        }

        if (preg_match(self::NAME, $tokens[1]['text']) !== 1) {
            return null;
        }

        return [
            'name' => $tokens[1]['text'],
            'enabled' => $enabled,
            'command' => strtolower($tokens[0]['text']),
            'indent' => $indent,
            'comment' => $comment === null ? '' : ' ' . ltrim(substr($body, $comment)),
        ];
    }

    /**
     * Apply the resource list as the form left it. Rows carry the key resources() gave them;
     * a row without one is new, a key that is gone is a removed resource.
     *
     * @param  array<int, array{key?: ?string, name?: ?string, enabled?: bool}>  $rows
     */
    public function setResources(array $rows): void
    {
        $existing = [];

        foreach ($this->lines as $index => $line) {
            $resource = self::resourceLine($line);

            if ($resource !== null) {
                $existing[$index] = $resource;
            }
        }

        $new = [];
        $kept = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $enabled = (bool) ($row['enabled'] ?? true);

            if ($name === '' || preg_match(self::NAME, $name) !== 1) {
                continue;
            }

            $key = $row['key'] ?? null;

            if (is_string($key) && ctype_digit($key) && isset($existing[(int) $key])) {
                $index = (int) $key;
                $kept[$index] = true;
                $old = $existing[$index];

                if ($old['name'] !== $name || $old['enabled'] !== $enabled) {
                    $this->replace[$index] = $old['indent'] . ($enabled ? '' : '# ') . $old['command'] . ' ' . $name . $old['comment'];
                }

                continue;
            }

            $new[] = ($enabled ? '' : '# ') . 'ensure ' . $name;
        }

        foreach (array_keys($existing) as $index) {
            if (!isset($kept[$index])) {
                $this->delete[$index] = true;
            }
        }

        $this->insertAfterLast(array_keys($existing), $new);
    }

    // --- permissions -------------------------------------------------------------------------

    /**
     * @return array<int, array{key: string, child: string, parent: string}>
     */
    public function principals(): array
    {
        $rows = [];

        foreach ($this->lines as $index => $line) {
            $words = self::words($line);

            if (count($words) >= 3 && strtolower($words[0]) === 'add_principal') {
                $rows[] = ['key' => (string) $index, 'child' => $words[1], 'parent' => $words[2]];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array{key?: ?string, child?: ?string, parent?: ?string}>  $rows
     */
    public function setPrincipals(array $rows): void
    {
        $this->setList('add_principal', 3, $rows, function (array $row): ?array {
            $child = trim((string) ($row['child'] ?? ''));
            $parent = trim((string) ($row['parent'] ?? ''));

            return preg_match(self::PRINCIPAL, $child) === 1 && preg_match(self::PRINCIPAL, $parent) === 1 ? [$child, $parent] : null;
        });
    }

    /**
     * @return array<int, array{key: string, principal: string, object: string, access: string}>
     */
    public function aces(): array
    {
        $rows = [];

        foreach ($this->lines as $index => $line) {
            $words = self::words($line);

            if (count($words) >= 4 && strtolower($words[0]) === 'add_ace') {
                $rows[] = ['key' => (string) $index, 'principal' => $words[1], 'object' => $words[2], 'access' => strtolower($words[3]) === 'deny' ? 'deny' : 'allow'];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array{key?: ?string, principal?: ?string, object?: ?string, access?: ?string}>  $rows
     */
    public function setAces(array $rows): void
    {
        $this->setList('add_ace', 4, $rows, function (array $row): ?array {
            $principal = trim((string) ($row['principal'] ?? ''));
            $object = trim((string) ($row['object'] ?? ''));
            $access = ($row['access'] ?? 'allow') === 'deny' ? 'deny' : 'allow';

            return preg_match(self::PRINCIPAL, $principal) === 1 && preg_match(self::PRINCIPAL, $object) === 1 ? [$principal, $object, $access] : null;
        });
    }

    /**
     * The shared shape of add_principal and add_ace: rows keyed by line, each line one command
     * with fixed arguments and maybe a trailing comment, which an unchanged or edited row keeps.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): ?array<int, string>  $arguments
     */
    private function setList(string $command, int $count, array $rows, callable $arguments): void
    {
        $existing = [];

        foreach ($this->lines as $index => $line) {
            [$tokens, $comment] = self::tokenize($line);

            if (count($tokens) >= $count && strtolower($tokens[0]['text']) === $command) {
                $existing[$index] = [
                    'arguments' => array_column(array_slice($tokens, 1, $count - 1), 'text'),
                    'indent' => (string) preg_replace('/^(\s*).*$/s', '$1', $line),
                    'comment' => $comment === null ? '' : ' ' . substr($line, $comment),
                ];
            }
        }

        $kept = [];
        $new = [];

        foreach ($rows as $row) {
            $args = $arguments($row);

            if ($args === null) {
                continue;
            }

            $key = $row['key'] ?? null;

            if (is_string($key) && ctype_digit($key) && isset($existing[(int) $key])) {
                $index = (int) $key;
                $kept[$index] = true;

                if ($existing[$index]['arguments'] !== $args) {
                    $this->replace[$index] = $existing[$index]['indent'] . $command . ' ' . implode(' ', $args) . $existing[$index]['comment'];
                }

                continue;
            }

            $new[] = $command . ' ' . implode(' ', $args);
        }

        foreach (array_keys($existing) as $index) {
            if (!isset($kept[$index])) {
                $this->delete[$index] = true;
            }
        }

        $this->insertAfterLast(array_keys($existing), $new);
    }

    /**
     * @param  array<int, int>  $indexes
     * @param  array<int, string>  $lines
     */
    private function insertAfterLast(array $indexes, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        if ($indexes === []) {
            array_push($this->append, ...$lines);

            return;
        }

        $last = max($indexes);
        $this->after[$last] = array_merge($this->after[$last] ?? [], $lines);
    }

    // --- values ------------------------------------------------------------------------------

    /**
     * One line, no double quotes: the cfg grammar has no escapes, so a quote would end the
     * token and a newline would start a new command.
     */
    public static function clean(string $value): string
    {
        return trim(str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $value));
    }

    private static function format(string $value, bool $quote): string
    {
        return $quote || preg_match('/[\s#;]|^\/\//', $value) === 1 || $value === '' ? '"' . $value . '"' : $value;
    }

    /**
     * Whether the file makes this a RedM server (txAdmin's RedM recipes set gamename rdr3).
     */
    public function isRedM(): bool
    {
        return strtolower((string) $this->get('gamename')) === 'rdr3';
    }
}
