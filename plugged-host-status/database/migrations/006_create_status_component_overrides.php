<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_component_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // Which component this control targets: all | site | panel | node:{public_id}.
            $table->string('component_key', 64);
            $table->string('status', 32);
            // Optional public annotation shown on the status page next to the component.
            $table->text('note')->nullable();
            // Optional active window. Null start = active immediately; null end = until cleared.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['component_key', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_component_overrides');
    }
};
