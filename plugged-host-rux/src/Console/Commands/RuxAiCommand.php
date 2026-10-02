<?php

namespace PluggedHost\Rux\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Rux\Agent\Ai\AiUnavailableException;
use PluggedHost\Rux\Agent\Ai\NeoProviders;
use PluggedHost\Rux\Support\RuxSupervisor;

/**
 * Show the AI providers rux has from neo. rux fetches them by itself (NeoProviders); this
 * fetches them now and shows what came back.
 */
class RuxAiCommand extends Command
{
    protected $signature = 'plugged-host:rux-ai';

    protected $description = 'Fetch neo\'s AI providers for rux now and show them';

    public function handle(RuxSupervisor $supervisor, NeoProviders $neo): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        try {
            $neo->refresh();
            $this->info('Fetched neo\'s providers from noprofit.space just now.');
        } catch (AiUnavailableException $e) {
            $fetchedAt = $neo->fetchedAt();
            $this->error("Could not fetch neo's providers ({$e->getMessage()}).");

            if ($fetchedAt === null) {
                return self::FAILURE;
            }

            $this->warn('rux keeps using the ones it fetched ' . now()->setTimestamp($fetchedAt)->diffForHumans() . ':');
        }

        $this->table(['Slot', 'Label', 'API format', 'Model', 'Enabled', 'Key'], array_map(static fn (array $p): array => [
            'P' . ($p['slot'] ?? '?'),
            (string) ($p['label'] ?? ''),
            (string) ($p['driver'] ?? ''),
            (string) ($p['model'] ?? ''),
            !empty($p['enabled']) ? 'yes' : 'no',
            ($p['api_key'] ?? '') !== '' ? 'set' : 'MISSING',
        ], $neo->providers()));

        return self::SUCCESS;
    }
}
