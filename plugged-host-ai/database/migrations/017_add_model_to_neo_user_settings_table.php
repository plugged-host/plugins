<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The model the user picked in the chat: standard ("Other models") or premium (the premium slot
 * on noprofit.space, PlusPlus only). The same filename on every branch (migration names are global).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('neo_user_settings', 'model')) {
            return;
        }

        Schema::table('neo_user_settings', function (Blueprint $table): void {
            $table->string('model', 16)->default('standard')->after('auto_approve');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('neo_user_settings', 'model')) {
            Schema::table('neo_user_settings', function (Blueprint $table): void {
                $table->dropColumn('model');
            });
        }
    }
};
