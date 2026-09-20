<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The start queue's two tables.
 *
 * Two, not three. The line itself and the paid priority window are kept apart
 * because they have opposite lifetimes and opposite failure costs: an entry is
 * scratch state that a sweep is allowed to delete, and a priority window is
 * something a user paid fifteen tokens for. One table with a `kind` column would
 * put both under the same sweep and the same unique index — and MySQL treats NULLs
 * in a unique index as distinct, so the account-wide row could silently duplicate.
 *
 * There is no third table for the post-release grace pass. That one churns once per
 * start per user, is worth minutes, and losing the lot to `optimize:clear` costs one
 * extra wait — so it lives in the cache, next to the ad gate's pass, which works the
 * same way for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('start_queue_entries')) {
            Schema::create('start_queue_entries', function (Blueprint $table) {
                $table->increments('id');

                $table->unsignedInteger('user_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

                // One row per server, reused. A server can only be in one line at a
                // time, so the unique index *is* the double-enqueue guard: pressing
                // Start twice, or in two tabs, updates the row it already has instead
                // of racing to insert a second place in the queue. It also bounds the
                // table at one row per server rather than one per start ever made —
                // the queue keeps no history, because the analytics that matter are
                // already recorded on plugged-host-ads' start-gate events.
                $table->unsignedInteger('server_id')->unique();
                $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

                // Denormalised from the server on purpose. The line is per node, and
                // reading it must not join to servers on every poll; it is also the
                // node the wait was *judged against*, which is not necessarily where
                // the server lives by the time it is released — a transfer out of the
                // queue changes one and not the other.
                $table->unsignedInteger('node_id')->index();
                $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();

                // start | restart — replayed verbatim on release.
                $table->string('action', 16);

                // waiting | released | cancelled | expired
                $table->string('status', 16)->default('waiting');

                // Stamped at enqueue from the user's active window, and re-stamped if
                // they buy one while already in the line. A column rather than a join
                // so the ordering below is one index scan.
                $table->boolean('priority')->default(false);

                $table->timestamp('queued_at')->useCurrent();
                // Heartbeat from the waiting page's poll. Only the sweep reads it, and
                // only to drop lines nobody is standing in.
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('released_at')->nullable();

                $table->json('metadata')->nullable();
                $table->timestamps();

                // The ordering, exactly: priority first, then arrival, then id as the
                // tie-break for two rows inside the same second. Scoped by node and
                // status because that is the only question ever asked of this table.
                $table->index(
                    ['node_id', 'status', 'priority', 'queued_at', 'id'],
                    'start_queue_entries_line_order',
                );
            });
        }

        if (!Schema::hasTable('start_queue_priorities')) {
            Schema::create('start_queue_priorities', function (Blueprint $table) {
                $table->increments('id');

                // One window per account, extended rather than stacked. Unique so a
                // double-clicked purchase cannot open two windows the user paid for
                // once — the second insert is a constraint violation, which the
                // service turns into an extension of the first.
                $table->unsignedInteger('user_id')->unique();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

                $table->timestamp('expires_at')->index();
                // Cumulative, over the account's whole history: what this user has
                // actually paid us in tokens, which no ledger row can be trusted to
                // still hold once transactions are pruned.
                $table->unsignedInteger('tokens_spent')->default(0);
                $table->unsignedInteger('purchases')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('start_queue_priorities');
        Schema::dropIfExists('start_queue_entries');
    }
};
