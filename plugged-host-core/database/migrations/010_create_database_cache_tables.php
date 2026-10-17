<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables Laravel's `database` cache store and lock need, plus an index for session GC.
 *
 * Since 2026-10-17 prod runs on two panel boxes behind one proxy, and either box may answer any
 * request. Sessions, cache, queue and maintenance mode therefore live in the replicated
 * database (SESSION_DRIVER / CACHE_STORE / QUEUE_CONNECTION=database,
 * APP_MAINTENANCE_DRIVER=cache) instead of a Redis that only one box has. Pelican ships the
 * `sessions` and `jobs` tables but not `cache`/`cache_locks` (it defaults to Redis or file), so
 * they come from here, in the shape of Laravel's own create_cache_table migration.
 *
 * Upstream's `sessions` table has no index on last_activity; the session lottery deletes by it
 * on 2% of requests, which would scan the whole table every time. The index is additive and
 * named the way Laravel names it, so a future upstream migration adding the same one can check
 * for it.
 *
 * Every step checks first, so the migration is a no-op where the tables already exist (dev,
 * which keeps Redis, gets the tables and never uses them).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
        }

        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }

        if (Schema::hasTable('sessions') && !$this->hasIndex('sessions', 'sessions_last_activity_index')) {
            Schema::table('sessions', function (Blueprint $table): void {
                $table->index('last_activity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sessions') && $this->hasIndex('sessions', 'sessions_last_activity_index')) {
            Schema::table('sessions', function (Blueprint $table): void {
                $table->dropIndex('sessions_last_activity_index');
            });
        }

        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $i): bool => $i['name'] === $index);
    }
};
