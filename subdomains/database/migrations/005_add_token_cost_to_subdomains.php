<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subdomains', function (Blueprint $table) {
            $table->unsignedInteger('token_cost')->default(0)->after('record_type');
        });
    }

    public function down(): void
    {
        Schema::table('subdomains', function (Blueprint $table) {
            $table->dropColumn('token_cost');
        });
    }
};
