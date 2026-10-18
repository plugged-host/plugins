<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per decrease of an account's allowance its servers have to give back
 * (Services\AllowanceTrim): the account's base went down on noprofit.space. cpu (%),
 * memory and disk (MB) are the most that comes off; the servers give what they use beyond the
 * allowance, up to that. Written in the transaction that took on the lower limits, settled
 * once carried out, with what came off which server in `result`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('allowance_cuts')) {
            return;
        }

        Schema::create('allowance_cuts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('reason', 64);
            $table->unsignedInteger('cpu')->default(0);
            $table->unsignedInteger('memory')->default(0);
            $table->unsignedInteger('disk')->default(0);
            $table->json('result')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['settled_at', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_cuts');
    }
};
