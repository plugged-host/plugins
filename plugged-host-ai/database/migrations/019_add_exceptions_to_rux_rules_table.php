<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a standing rule must NOT act on (`exceptions`: the near misses an admin had to spell out
 * inside the description before), room for longer descriptions, and the message a watched
 * channel's message replied to (`replied_to`), so the AI judges an answer with its question.
 * The same filename on every branch (migration names are global).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rux_rules', function (Blueprint $table): void {
            $table->string('description', 600)->change();

            if (!Schema::hasColumn('rux_rules', 'exceptions')) {
                $table->string('exceptions', 600)->nullable()->after('description');
            }
        });

        if (!Schema::hasColumn('rux_watch_queue', 'replied_to')) {
            Schema::table('rux_watch_queue', function (Blueprint $table): void {
                $table->text('replied_to')->nullable()->after('content');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rux_watch_queue', 'replied_to')) {
            Schema::table('rux_watch_queue', function (Blueprint $table): void {
                $table->dropColumn('replied_to');
            });
        }

        if (Schema::hasColumn('rux_rules', 'exceptions')) {
            Schema::table('rux_rules', function (Blueprint $table): void {
                $table->dropColumn('exceptions');
            });
        }
    }
};
