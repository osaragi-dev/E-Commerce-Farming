<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('stock permission migration preserves assignments and removes only obsolete permissions', function (bool $targetExists, string $guard): void {
    $role = Role::factory()->create(['guard_name' => $guard]);
    $user = User::factory()->sso()->create();
    $legacy = Permission::findOrCreate('Export:WarehouseStock', $guard);
    $obsolete = Permission::findOrCreate('ViewAny:WarehouseStock', $guard);
    $unrelated = Permission::findOrCreate('ViewAny:Warehouse', $guard);
    $lookalike = Permission::findOrCreate('ViewAny:WarehouseStockExtra', $guard);
    $teamKey = config('permission.column_names.team_foreign_key');
    Schema::table('model_has_permissions', function (Blueprint $table) use ($teamKey): void {
        $table->uuid($teamKey)->nullable();
    });
    $team = (string) Str::uuid7();
    foreach ([$legacy, $obsolete, $unrelated] as $permission) {
        DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        DB::table('model_has_permissions')->insert([
            'model_id' => $user->id, 'model_type' => $user->getMorphClass(),
            'permission_id' => $permission->id, $teamKey => $team,
        ]);
    }
    if ($targetExists) {
        $target = Permission::findOrCreate('Export:Stock', $guard);
        DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $target->id]);
        DB::table('model_has_permissions')->insert([
            'model_id' => $user->id, 'model_type' => $user->getMorphClass(),
            'permission_id' => $target->id, $teamKey => $team,
        ]);
    }

    $migration = require database_path('migrations/2026_10_10_103421_consolidate_stock_permissions.php');
    $migration->up();
    $migration->up();

    $target = Permission::findByName('Export:Stock', $guard);
    expect(DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $target->id)->count())->toBe(1)
        ->and(DB::table('model_has_permissions')->where('model_id', $user->id)->where('permission_id', $target->id)->value($teamKey))->toBe($team)
        ->and(Permission::query()->where('name', 'like', '%:WarehouseStock')->count())->toBe(0)
        ->and(Permission::query()->whereKey($lookalike->id)->exists())->toBeTrue()
        ->and(DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $unrelated->id)->exists())->toBeTrue()
        ->and(DB::table('role_has_permissions')->where('role_id', $role->id)->count())->toBe(2)
        ->and(DB::table('model_has_permissions')->where('model_id', $user->id)->count())->toBe(2);
})->with([
    'new permission' => [false, 'web'],
    'existing permission' => [true, 'web'],
    'other guard' => [false, 'api'],
]);
