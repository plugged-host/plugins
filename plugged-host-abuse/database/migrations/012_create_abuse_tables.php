<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the abuse checks found: one row per server (or node, for the agent's host scan)
        // and rule, updated on every hit rather than appended. See PluggedHost\Abuse\Models\AbuseFinding.
        Schema::create('plugged_host_abuse_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('fingerprint', 64)->unique();
            $table->unsignedInteger('server_id')->nullable();
            $table->unsignedInteger('node_id')->nullable();
            $table->string('rule', 64);
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->string('source', 16);
            $table->string('status', 16)->default('open');
            $table->string('summary', 500);
            $table->json('evidence')->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('action', 32)->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'last_seen_at']);
        });

        // The node agent's heartbeat, one row per node it reports from.
        Schema::create('plugged_host_abuse_agents', function (Blueprint $table): void {
            $table->unsignedInteger('node_id')->primary();
            $table->string('version', 32)->nullable();
            $table->string('hostname')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('stats')->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->timestamps();

            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugged_host_abuse_agents');
        Schema::dropIfExists('plugged_host_abuse_findings');
    }
};
