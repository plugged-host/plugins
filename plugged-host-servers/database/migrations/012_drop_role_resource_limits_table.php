<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role limits are gone: what an account may use is its plan on noprofit.space (the default
 * tier, or the Discord tier while its Discord account is in the server), see AccountAllowance.
 * The last thing a role row still decided was the server count, and prod never set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('role_resource_limits');
    }

    public function down(): void
    {
        if (Schema::hasTable('role_resource_limits')) {
            return;
        }

        Schema::create('role_resource_limits', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('role_id')->unique();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->unsignedInteger('cpu');
            $table->unsignedInteger('memory');
            $table->unsignedInteger('disk');
            $table->unsignedInteger('server_limit')->nullable();
            $table->timestamps();
        });
    }
};
