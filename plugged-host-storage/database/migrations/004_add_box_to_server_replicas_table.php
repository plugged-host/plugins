<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One replica row per server and box (storage box, backup server). Existing rows are the
    // storage box's. The new unique index comes first: it also serves the server_id foreign key,
    // which would not let the old one go otherwise.
    public function up(): void
    {
        if (Schema::hasColumn('server_replicas', 'box')) {
            return;
        }

        Schema::table('server_replicas', function (Blueprint $table) {
            $table->string('box', 16)->default('storage')->after('server_id');
            $table->unique(['server_id', 'box']);
        });

        Schema::table('server_replicas', function (Blueprint $table) {
            $table->dropUnique(['server_id']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('server_replicas', 'box')) {
            return;
        }

        DB::table('server_replicas')->where('box', '!=', 'storage')->delete();

        Schema::table('server_replicas', function (Blueprint $table) {
            $table->unique(['server_id']);
        });

        Schema::table('server_replicas', function (Blueprint $table) {
            $table->dropUnique(['server_id', 'box']);
            $table->dropColumn('box');
        });
    }
};
