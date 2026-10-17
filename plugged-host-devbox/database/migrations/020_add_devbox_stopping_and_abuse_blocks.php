<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when a stop was asked for; the token is only retired (token_hash null) once Wings
        // says the devbox is offline, so a stop that Wings did not carry out is tried again.
        Schema::table('plugged_host_devboxes', function (Blueprint $table) {
            $table->timestamp('stopping_at')->nullable()->after('token_hash');
        });

        // People who may not use devboxes because the abuse checks raised a critical finding on
        // one of their devboxes. Lifted when that finding is dismissed or resolved. Kept apart
        // from the finding: the finding goes with its server (foreign key cascade), the block
        // must not.
        Schema::create('plugged_host_devbox_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('finding_id');
            $table->unsignedInteger('server_id')->nullable();
            $table->string('rule', 64)->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'finding_id']);
            $table->index('finding_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugged_host_devbox_blocks');

        Schema::table('plugged_host_devboxes', function (Blueprint $table) {
            $table->dropColumn('stopping_at');
        });
    }
};
