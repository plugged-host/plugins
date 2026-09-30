<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transcript, in the canonical chat shape the gateway speaks: user / assistant (with
 * tool_calls) / tool (with tool_call_id). Tool rows double as the approval queue through
 * status; notice rows are shown in the chat but never sent to the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('neo_messages')) {
            return;
        }

        Schema::create('neo_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedInteger('seq');
            $table->string('role', 12);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 64)->nullable();
            $table->string('tool_name', 64)->nullable();
            $table->string('status', 24)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('neo_conversations')->cascadeOnDelete();
            $table->unique(['conversation_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neo_messages');
    }
};
