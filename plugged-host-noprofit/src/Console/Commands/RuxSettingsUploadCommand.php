<?php

namespace PluggedHost\Noprofit\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Noprofit\Ai\NoprofitRuxSettings;
use RuntimeException;

/**
 * Moves this panel's own rux settings (the rux tab's, config plugged-host-ai.rux) to noprofit.space,
 * where Admin > rux keeps them from then on, so nothing has to be typed in again. noprofit.space
 * takes them once: when it keeps its own already nothing is sent (unless --replace). Afterwards
 * it fetches them back and compares every field with this panel's; rux runs on noprofit.space's
 * from the next fetch on (within a minute; at once in this process).
 *
 *   php artisan plugged-host:rux-settings-upload --dry-run   # what would be sent, nothing sent
 *   php artisan plugged-host:rux-settings-upload             # send once, then compare
 *   php artisan plugged-host:rux-settings-upload --verify    # compare only
 *
 * The bot token is sent (noprofit.space keeps it encrypted) but never printed: only whether it is
 * set and the start of its SHA-1.
 */
class RuxSettingsUploadCommand extends Command
{
    protected $signature = 'plugged-host:rux-settings-upload
        {--dry-run : Show what would be sent and compare with noprofit.space, send nothing}
        {--verify : Only compare this panel\'s rux settings with noprofit.space\'s}
        {--replace : Send even when noprofit.space keeps its own settings already (overwrites them)}';

    protected $description = 'Move this panel\'s rux settings to noprofit.space (Admin > rux) once, and compare them';

    public function handle(NoprofitRuxSettings $source): int
    {
        if (!$source->configured()) {
            $this->error('noprofit.space is not configured on this panel (plugged-host-noprofit: its URL and sync secret).');

            return self::FAILURE;
        }

        $panel = NoprofitRuxSettings::forNoprofit((array) config('plugged-host-ai.rux', []));

        try {
            $remote = $source->refresh();
        } catch (RuntimeException $e) {
            $this->error('Could not read rux\'s settings on noprofit.space: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('verify')) {
            if (!$remote['configured']) {
                $this->warn('noprofit.space keeps no rux settings yet: this panel\'s own apply.');

                return self::FAILURE;
            }

            return $this->compare($panel, (array) $remote['settings']) ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Would send (nothing sent):');
            $this->table(['Setting', 'Value'], array_map(
                static fn (string $key, mixed $value): array => [$key, self::show($key, $value)],
                array_keys(self::flatten($panel)),
                self::flatten($panel),
            ));

            if ($remote['configured']) {
                $this->warn('noprofit.space keeps its own rux settings already (saved ' . ($remote['saved_at'] ?? '?') . ' by ' . ($remote['saved_by'] ?? '?') . '): only --replace would send these.');
                $this->compare($panel, (array) $remote['settings']);
            }

            return self::SUCCESS;
        }

        if ($remote['configured'] && !$this->option('replace')) {
            $this->warn('noprofit.space keeps its own rux settings already (saved ' . ($remote['saved_at'] ?? '?') . ' by ' . ($remote['saved_by'] ?? '?') . '); nothing sent. They are edited on Admin > rux there.');
            $this->compare($panel, (array) $remote['settings']);

            return self::SUCCESS;
        }

        try {
            $answer = $source->upload($panel, ['id' => 'cli', 'name' => 'plugged-host:rux-settings-upload'], (bool) $this->option('replace'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($answer['status'] !== 200) {
            $this->error('noprofit.space did not take them (HTTP ' . $answer['status'] . '): ' . (string) ($answer['body']['message'] ?? $answer['body']['error'] ?? ''));

            foreach ((array) ($answer['body']['errors'] ?? []) as $field => $messages) {
                $this->line("  {$field}: " . implode(' ', array_map('strval', (array) $messages)));
            }

            return self::FAILURE;
        }

        $this->info('noprofit.space took them (changed: ' . (implode(', ', array_map('strval', (array) ($answer['body']['changed'] ?? []))) ?: 'nothing') . '; bot token ' . (!empty($answer['body']['bot_token_set']) ? 'set' : 'not set') . ').');

        try {
            $remote = $source->refresh();
        } catch (RuntimeException $e) {
            $this->error('Sent, but could not read them back: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (!$remote['configured']) {
            $this->error('Sent, but noprofit.space still says it keeps no rux settings.');

            return self::FAILURE;
        }

        return $this->compare($panel, (array) $remote['settings']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every field of this panel's settings next to noprofit.space's; true when all are the same.
     *
     * @param  array<string, mixed>  $panel
     * @param  array<string, mixed>  $remote
     */
    private function compare(array $panel, array $remote): bool
    {
        $mine = self::flatten($panel);
        $theirs = self::flatten(NoprofitRuxSettings::forNoprofit(NoprofitRuxSettings::overlay([], $remote)));
        $rows = [];
        $same = true;

        foreach (array_unique([...array_keys($mine), ...array_keys($theirs)]) as $key) {
            $here = array_key_exists($key, $mine) ? $mine[$key] : null;
            $there = array_key_exists($key, $theirs) && self::present($remote, $key) ? $theirs[$key] : null;
            $equal = $here === $there;
            $same = $same && $equal;
            $rows[] = [$key, self::show($key, $here), self::show($key, $there), $equal ? 'yes' : 'NO'];
        }

        $this->table(['Setting', 'This panel', 'noprofit.space', 'Same'], $rows);
        $same
            ? $this->info('Every setting is the same on noprofit.space.')
            : $this->error('Some settings differ (NO above).');

        return $same;
    }

    /**
     * Whether noprofit.space's answer has this field at all (overlay() keeps a missing one empty,
     * which must not count as "the same").
     *
     * @param  array<string, mixed>  $remote
     */
    private static function present(array $remote, string $key): bool
    {
        if (preg_match('/^servers\.(\d+)\.(\w+)$/', $key, $match) === 1) {
            $servers = array_values(array_filter((array) ($remote['servers'] ?? []), 'is_array'));

            return array_key_exists($match[2], $servers[(int) $match[1]] ?? []);
        }

        return data_get($remote, $key, "\0missing") !== "\0missing";
    }

    /**
     * "spam.enabled" => true, "servers.0.id" => "…"; lists of values stay lists.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function flatten(array $settings, string $prefix = ''): array
    {
        $flat = [];

        foreach ($settings as $key => $value) {
            $path = $prefix . $key;

            if (is_array($value) && $value !== [] && (!array_is_list($value) || is_array($value[0]))) {
                $flat += self::flatten($value, $path . '.');
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private static function show(string $key, mixed $value): string
    {
        if ($key === 'bot_token') {
            return is_string($value) && $value !== '' ? 'set (sha1 ' . substr(sha1($value), 0, 8) . ')' : 'not set';
        }

        return match (true) {
            $value === null => '(missing)',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => $value === [] ? '[]' : implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }
}
