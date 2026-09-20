<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_credits')) {
            Schema::create('user_credits', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->unique();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->unsignedInteger('credits')->default(0);
                $table->unsignedInteger('extra_cpu')->default(0);
                $table->unsignedInteger('extra_memory')->default(0);
                $table->unsignedInteger('extra_disk')->default(0);
                $table->unsignedInteger('extra_server_limit')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('credit_transactions')) {
            Schema::create('credit_transactions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->integer('amount');
                $table->unsignedInteger('balance_after')->default(0);
                $table->string('type', 64)->index();
                $table->string('description');
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('user_credits');
    }
};
