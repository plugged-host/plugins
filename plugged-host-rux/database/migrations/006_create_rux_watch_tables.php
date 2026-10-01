<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channels where rux checks every member message (WatchService), and the queue of messages
     * waiting for that check. `channel` is a channel id, or a name resolved against the server's
     * channels (so the seeded "chat" also finds "💬│chat").
     */
    public function up(): void
    {
        Schema::create('rux_watches', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 100)->unique();
            $table->string('added_by_discord_id', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('rux_watch_queue', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 32);
            $table->string('channel_id', 32);
            $table->string('author_id', 32);
            $table->string('author_name', 100)->nullable();
            $table->json('roles')->nullable();
            $table->text('content');
            $table->timestamp('created_at')->nullable()->index();
        });

        $now = now();
        DB::table('rux_watches')->insert([
            ['channel' => 'chat', 'created_at' => $now, 'updated_at' => $now],
            ['channel' => 'bot-commands', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rux_watch_queue');
        Schema::dropIfExists('rux_watches');
    }
};
