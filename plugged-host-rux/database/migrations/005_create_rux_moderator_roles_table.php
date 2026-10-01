<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discord roles whose members may give rux moderation orders ("@Helpers can u give them mod
     * permissions"). Their orders are metered against the admin who made the role moderators.
     * permissions_added is what rux added to the role's own Discord permissions, so taking the
     * role back removes exactly that.
     */
    public function up(): void
    {
        Schema::create('rux_moderator_roles', function (Blueprint $table) {
            $table->id();
            $table->string('guild_id', 32);
            $table->string('role_id', 32)->unique();
            $table->string('role_name', 100)->nullable();
            $table->string('granted_by_discord_id', 32);
            $table->unsignedBigInteger('granted_by_account_id');
            $table->string('permissions_added', 32)->default('0');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rux_moderator_roles');
    }
};
