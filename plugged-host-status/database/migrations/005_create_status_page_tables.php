<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_node_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('node_id')->unique();
            $table->uuid('public_id')->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('unknown');
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('healthy_count')->default(0);
            $table->unsignedInteger('resource_pressure_count')->default(0);
            $table->json('last_metrics')->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();

            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->index(['enabled', 'sort_order']);
        });

        Schema::create('status_snapshots', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('component_type', 32);
            $table->string('component_key', 64);
            $table->string('status', 32);
            $table->json('metrics')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['component_key', 'checked_at']);
            $table->index('checked_at');
        });

        Schema::create('status_daily_uptime', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('component_type', 32);
            $table->string('component_key', 64);
            $table->date('date');
            $table->unsignedInteger('total_checks')->default(0);
            $table->unsignedInteger('operational_checks')->default(0);
            $table->string('worst_status', 32)->default('unknown');
            $table->timestamps();

            $table->unique(['component_key', 'date']);
            $table->index('date');
        });

        Schema::create('status_incidents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('title');
            $table->string('impact', 32)->default('minor');
            $table->string('status', 32)->default('investigating');
            $table->json('affected_components')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'started_at']);
        });

        Schema::create('status_incident_updates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('status_incident_id');
            $table->string('status', 32);
            $table->text('message');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->foreign('status_incident_id')->references('id')->on('status_incidents')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status_incident_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_incident_updates');
        Schema::dropIfExists('status_incidents');
        Schema::dropIfExists('status_daily_uptime');
        Schema::dropIfExists('status_snapshots');
        Schema::dropIfExists('status_node_settings');
    }
};
