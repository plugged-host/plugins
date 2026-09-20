<?php

namespace PluggedHost\Queue\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Queue\Models\StartQueueEntry;
use PluggedHost\Queue\Services\NodePressure;
use PluggedHost\Queue\Services\StartQueueService;
use Throwable;

/**
 * The part of the queue that runs without anybody watching.
 *
 * Scheduled every minute by `QueuePluginProvider`, and it is what makes the waiting
 * page optional rather than load-bearing: a user who presses Start and closes the tab
 * has still asked for a start, so the sweep releases and dispatches on their behalf
 * when the node has room or when the hard cap runs out. The page only ever makes that
 * happen sooner.
 *
 * `--status` is the operator's window into all of this. There is no admin screen for
 * the line — it is scratch state that is usually empty — and this is how you find out
 * who is waiting, on which node, and why they have not been let through yet.
 */
class QueueSweepCommand extends Command
{
    protected $signature = 'plugged-host:queue-sweep
        {--status : Show the current lines and what is holding them, and change nothing}
        {--release : Release every waiting entry now, whatever the nodes look like}';

    protected $description = 'Release start-queue entries whose node has room or whose wait has run out.';

    public function handle(StartQueueService $queue, NodePressure $pressure): int
    {
        if ($this->option('status')) {
            return $this->showStatus($queue, $pressure);
        }

        if ($this->option('release')) {
            return $this->releaseEverything($queue);
        }

        try {
            $counts = $queue->sweep();
        } catch (Throwable $e) {
            $this->error("Sweep failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Silent when there was nothing to do. This runs sixty times an hour and its
        // output goes to the scheduler's log.
        if ($counts['released'] > 0 || $counts['expired'] > 0) {
            $this->info("Released {$counts['released']}, expired {$counts['expired']}.");
        }

        return self::SUCCESS;
    }

    protected function showStatus(StartQueueService $queue, NodePressure $pressure): int
    {
        $this->line('');
        $this->line('<fg=cyan>Start queue</>');
        $this->line(sprintf(
            '  enabled: %s   dimensions: %s   limit: %s%%',
            $queue->isEnabled() ? 'yes' : 'no',
            implode(', ', $pressure->dimensions()),
            number_format($pressure->limit(), 1),
        ));
        $this->line(sprintf(
            '  min wait: %ds   max wait: %ds   release interval: %ds   stale after: %ds',
            $queue->minWait(),
            $queue->maxWait(),
            $queue->releaseInterval(),
            $queue->staleAfter(),
        ));

        $entries = $queue->waitingEntries();

        if ($entries->isEmpty()) {
            $this->line('');
            $this->line('  Nobody is waiting.');
            $this->line('');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($entries as $entry) {
            $reason = $queue->releaseReason($entry);

            $rows[] = [
                $entry->getKey(),
                $entry->server?->name ?? "#{$entry->server_id}",
                $entry->node?->name ?? "#{$entry->node_id}",
                $entry->action,
                $entry->priority ? 'yes' : '',
                $queue->position($entry),
                $queue->elapsed($entry) . 's',
                $reason ?? $this->holdingReason($entry, $queue, $pressure),
            ];
        }

        $this->line('');
        $this->table(
            ['id', 'server', 'node', 'action', 'priority', 'place', 'waited', 'state'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Why this entry is still standing there.
     *
     * The same four questions `releaseReason()` asks, in the same order, reported rather
     * than acted on — the point of `--status` is to explain a line that is not moving
     * without having to read the source to find out which check is failing.
     */
    protected function holdingReason(StartQueueEntry $entry, StartQueueService $queue, NodePressure $pressure): string
    {
        if ($queue->elapsed($entry) < $queue->minWait()) {
            return 'min wait';
        }

        $dimension = $pressure->pressure($entry->node);

        if ($dimension !== null) {
            return "node hot ({$dimension})";
        }

        if (!$queue->nodeSlotFree((int) $entry->node_id)) {
            return 'spacing';
        }

        try {
            if ($entry->precedingCount() > 0) {
                return 'not first';
            }
        } catch (Throwable) {
            return 'unknown';
        }

        return 'ready';
    }

    /**
     * Empty every line by hand.
     *
     * For the case the queue was never meant to create: something is wrong with the
     * measurement, or a node is about to go down for maintenance and holding people in
     * front of it helps nobody. Releasing rather than cancelling, because every one of
     * these people asked for a start and is owed one.
     */
    protected function releaseEverything(StartQueueService $queue): int
    {
        $entries = $queue->waitingEntries();

        if ($entries->isEmpty()) {
            $this->info('Nobody is waiting.');

            return self::SUCCESS;
        }

        $released = 0;

        foreach ($entries as $entry) {
            if ($queue->release($entry, StartQueueService::REASON_FORCED)) {
                $released++;
                $this->line("  released #{$entry->getKey()} ({$entry->server?->name})");
            }
        }

        $this->info("Released {$released} of {$entries->count()}.");

        return self::SUCCESS;
    }
}
