<?php

namespace PluggedHost\Rux\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use PluggedHost\Rux\Support\RuxSupervisor;

/**
 * Copy neo's three AI providers (NEO_P1..3_* in noprofit.space's .env) into rux's own settings
 * (RUX_AI_P1..3_* in the panel's .env), 1:1. Run it again after changing neo's providers.
 */
class RuxCopyAiCommand extends Command
{
    private const FIELDS = ['ENABLED', 'LABEL', 'DRIVER', 'ENDPOINT', 'API_KEY', 'MODEL', 'MAX_TOKENS', 'TIMEOUT', 'DAILY_TOKEN_CAP', 'EXTRA_HEADERS_B64', 'EXTRA_BODY_B64'];

    protected $signature = 'plugged-host:rux-copy-ai {--dry-run : Show what would be copied, write nothing}';

    protected $description = 'Copy neo\'s AI providers (main, backup, extra backup) into rux\'s settings, 1:1';

    public function handle(RuxSupervisor $supervisor): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        $path = (string) config('plugged-host-rux.ai.neo_env_path');
        $raw = @file_get_contents($path);

        if ($raw === false) {
            $this->error("Cannot read neo's settings at {$path} (RUX_NEO_ENV_PATH).");

            return self::FAILURE;
        }

        $neo = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^(NEO_P[123]_[A-Z0-9_]+)=(.*)$/', trim($line), $m) === 1) {
                $value = trim($m[2]);
                if (strlen($value) >= 2 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) {
                    $value = stripcslashes(substr($value, 1, -1));
                }
                $neo[$m[1]] = $value;
            }
        }

        $values = [];
        $rows = [];

        foreach ([1, 2, 3] as $slot) {
            foreach (self::FIELDS as $field) {
                if (array_key_exists("NEO_P{$slot}_{$field}", $neo)) {
                    $values["RUX_AI_P{$slot}_{$field}"] = $neo["NEO_P{$slot}_{$field}"];
                }
            }

            $rows[] = [
                "P{$slot}",
                $neo["NEO_P{$slot}_LABEL"] ?? '-',
                $neo["NEO_P{$slot}_DRIVER"] ?? '-',
                $neo["NEO_P{$slot}_MODEL"] ?? '-',
                $neo["NEO_P{$slot}_ENABLED"] ?? '-',
                ($neo["NEO_P{$slot}_API_KEY"] ?? '') !== '' ? 'set' : 'MISSING',
            ];
        }

        $this->table(['Slot', 'Label', 'Driver', 'Model', 'Enabled', 'Key'], $rows);

        if ($values === []) {
            $this->error('No NEO_P1..3 settings found there.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing written. ' . count($values) . ' settings would be copied.');

            return self::SUCCESS;
        }

        Env::writeVariables($values, base_path('.env'), true);
        Artisan::call('config:clear');

        $this->info('Copied ' . count($values) . ' settings into RUX_AI_P1..3. rux uses them from its next AI call.');

        return self::SUCCESS;
    }
}
