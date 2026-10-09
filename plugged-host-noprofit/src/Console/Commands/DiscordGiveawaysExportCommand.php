<?php

namespace PluggedHost\Noprofit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Discord\Models\DiscordGiveaway;
use PluggedHost\Discord\Models\DiscordGiveawayRecipient;

/**
 * Giveaways moved to noprofit.space (Admin › Giveaways). This writes the panel's giveaway
 * history as the JSON that `php artisan noprofit:giveaways-import <file>` reads there:
 * winners already paid here come over as history and are never paid again; winners still
 * waiting (they had not linked Discord) are paid by noprofit.space once they link it.
 *
 * Read-only on the panel side.
 */
class DiscordGiveawaysExportCommand extends Command
{
    protected $signature = 'plugged-host:discord-giveaways-export
        {file : Where to write the JSON (use - for stdout)}';

    protected $description = 'Export giveaway history for noprofit.space (noprofit:giveaways-import)';

    public function handle(): int
    {
        if (!Schema::hasTable('discord_giveaways')) {
            $this->warn('No giveaways table: nothing to export.');

            return self::SUCCESS;
        }

        $giveaways = DiscordGiveaway::query()
            ->with('recipients')
            ->orderBy('id')
            ->get()
            ->map(fn (DiscordGiveaway $giveaway): array => [
                'id' => (int) $giveaway->getKey(),
                'prize' => (string) $giveaway->prize,
                'currency' => (string) $giveaway->currency,
                'amount' => (int) $giveaway->amount,
                'created_at' => $giveaway->created_at?->toIso8601String(),
                'recipients' => $giveaway->recipients
                    ->map(fn (DiscordGiveawayRecipient $recipient): array => [
                        'discord_id' => (string) $recipient->discord_id,
                        'status' => (string) $recipient->status,
                        'paid_at' => $recipient->paid_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();

        $json = json_encode([
            'exported_at' => now()->toIso8601String(),
            'source' => 'plugged-host',
            'giveaways' => $giveaways->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $file = (string) $this->argument('file');

        if ($file === '-') {
            $this->line($json);

            return self::SUCCESS;
        }

        file_put_contents($file, $json . PHP_EOL);

        $recipients = $giveaways->sum(fn (array $giveaway): int => count($giveaway['recipients']));
        $waiting = $giveaways->sum(fn (array $giveaway): int => count(array_filter(
            $giveaway['recipients'],
            fn (array $recipient): bool => $recipient['status'] !== DiscordGiveawayRecipient::STATUS_PAID,
        )));

        $this->info("Wrote {$giveaways->count()} giveaway(s), {$recipients} winner(s), {$waiting} still unpaid, to {$file}.");

        return self::SUCCESS;
    }
}
