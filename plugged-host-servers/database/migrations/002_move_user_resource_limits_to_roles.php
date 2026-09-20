<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        if (!Schema::hasTable('user_resource_limits')) {
            return;
        }

        $profiles = DB::table('user_resource_limits')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $limits) => implode(':', [
                $limits->cpu,
                $limits->memory,
                $limits->disk,
                $limits->server_limit ?? 'unlimited',
            ]));

        $roleTable = config('permission.table_names.roles', 'roles');
        $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $modelMorphKey = config('permission.column_names.model_morph_key') ?? 'model_id';
        $userMorphClass = (new User())->getMorphClass();

        foreach ($profiles->values() as $index => $profile) {
            $name = 'User Creatable Servers Plan ' . ($index + 1);
            $suffix = 2;

            while (DB::table($roleTable)->where('name', $name)->where('guard_name', 'web')->exists()) {
                $name = 'User Creatable Servers Plan ' . ($index + 1) . " ($suffix)";
                $suffix++;
            }

            $now = now();
            $roleId = DB::table($roleTable)->insertGetId([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $limits = $profile->first();
            DB::table('role_resource_limits')->insert([
                'role_id' => $roleId,
                'cpu' => $limits->cpu,
                'memory' => $limits->memory,
                'disk' => $limits->disk,
                'server_limit' => $limits->server_limit,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($profile as $userLimits) {
                DB::table($modelHasRolesTable)->insertOrIgnore([
                    $rolePivotKey => $roleId,
                    'model_type' => $userMorphClass,
                    $modelMorphKey => $userLimits->user_id,
                ]);
            }
        }

        Schema::drop('user_resource_limits');
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_resource_limits')) {
            Schema::create('user_resource_limits', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->unique();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->unsignedInteger('cpu');
                $table->unsignedInteger('memory');
                $table->unsignedInteger('disk');
                $table->unsignedInteger('server_limit')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('role_resource_limits')) {
            $modelHasRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
            $rolePivotKey = config('permission.column_names.role_pivot_key') ?? 'role_id';
            $modelMorphKey = config('permission.column_names.model_morph_key') ?? 'model_id';
            $userMorphTypes = array_unique([(new User())->getMorphClass(), User::class]);

            $limitsByUser = DB::table('role_resource_limits as limits')
                ->join("$modelHasRolesTable as assignments", "assignments.$rolePivotKey", '=', 'limits.role_id')
                ->whereIn('assignments.model_type', $userMorphTypes)
                ->select([
                    "assignments.$modelMorphKey as user_id",
                    'limits.cpu',
                    'limits.memory',
                    'limits.disk',
                    'limits.server_limit',
                ])
                ->get()
                ->groupBy('user_id');

            foreach ($limitsByUser as $userId => $profiles) {
                if (!DB::table('users')->where('id', $userId)->exists()) {
                    continue;
                }

                $mostPermissive = fn (string $attribute): int => $profiles->contains(fn (object $limits) => $limits->{$attribute} === 0)
                    ? 0
                    : (int) $profiles->max($attribute);
                $serverLimit = $profiles->contains(fn (object $limits) => $limits->server_limit === null)
                    ? null
                    : (int) $profiles->max('server_limit');

                DB::table('user_resource_limits')->insertOrIgnore([
                    'user_id' => $userId,
                    'cpu' => $mostPermissive('cpu'),
                    'memory' => $mostPermissive('memory'),
                    'disk' => $mostPermissive('disk'),
                    'server_limit' => $serverLimit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::dropIfExists('role_resource_limits');
    }
};
