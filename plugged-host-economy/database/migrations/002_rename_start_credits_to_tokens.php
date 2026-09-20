<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Start credits became tokens on 2026-08-31, and the ledger moved to
 * plugged-host-economy.
 *
 * Renames rather than a create-and-copy, which is the whole point: `RENAME TABLE` on
 * MariaDB is a metadata operation that carries the foreign keys, the indexes and their
 * names across with the data. A copy would have had to reproduce
 * `ads_claims_provider_token_unique` by hand, and that index is the only thing
 * standing between us and a replayed provider token being paid twice.
 *
 * Why the rename lives in *this* plugin when two of the three tables it produces are
 * read by plugged-host-economy: the rows are here, migration 001 is here, and Laravel
 * orders migrations by filename across every registered path — so a `002_` in the ads
 * plugin is guaranteed to run after ads' own `001_`, which is the only ordering this
 * needs. An economy-side migration would have had no such guarantee, and the economy
 * therefore ships with no migrations at all.
 *
 * Every step is guarded on the current shape rather than on "has 002 run", so a
 * half-applied run (the box died between two renames) is finished off by running it
 * again. `ads_start_gate_events` is deliberately untouched: it is the gate's own
 * telemetry, it never held a balance, and its `outcome` column still carries
 * historical `credit` rows that must keep rendering.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ads_start_credits') && !Schema::hasTable('user_tokens')) {
            Schema::rename('ads_start_credits', 'user_tokens');
        }

        // The column, not just the table: `credits` on a table called `user_tokens`
        // would be a trap for the next person to read UserTokens.
        if (Schema::hasTable('user_tokens')
            && Schema::hasColumn('user_tokens', 'credits')
            && !Schema::hasColumn('user_tokens', 'tokens')) {
            Schema::table('user_tokens', function (Blueprint $table) {
                $table->renameColumn('credits', 'tokens');
            });
        }

        if (Schema::hasTable('ads_start_credit_transactions') && !Schema::hasTable('token_transactions')) {
            Schema::rename('ads_start_credit_transactions', 'token_transactions');
        }

        if (Schema::hasTable('ads_start_credit_claims') && !Schema::hasTable('token_claims')) {
            Schema::rename('ads_start_credit_claims', 'token_claims');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('token_claims') && !Schema::hasTable('ads_start_credit_claims')) {
            Schema::rename('token_claims', 'ads_start_credit_claims');
        }

        if (Schema::hasTable('token_transactions') && !Schema::hasTable('ads_start_credit_transactions')) {
            Schema::rename('token_transactions', 'ads_start_credit_transactions');
        }

        if (Schema::hasTable('user_tokens')
            && Schema::hasColumn('user_tokens', 'tokens')
            && !Schema::hasColumn('user_tokens', 'credits')) {
            Schema::table('user_tokens', function (Blueprint $table) {
                $table->renameColumn('tokens', 'credits');
            });
        }

        if (Schema::hasTable('user_tokens') && !Schema::hasTable('ads_start_credits')) {
            Schema::rename('user_tokens', 'ads_start_credits');
        }
    }
};
