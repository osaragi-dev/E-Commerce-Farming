<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tables = config('permission.table_names');
            $permissionKey = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
            $legacyPermissions = DB::table($tables['permissions'])
                ->where('name', 'like', '%:WarehouseStock')
                ->get();

            foreach ($legacyPermissions->pluck('guard_name')->push('web')->unique() as $guard) {
                $target = Permission::findOrCreate('Export:Stock', $guard);

                foreach ($legacyPermissions->where('name', 'Export:WarehouseStock')->where('guard_name', $guard) as $legacy) {
                    foreach (['role_has_permissions', 'model_has_permissions'] as $pivotTable) {
                        foreach (DB::table($tables[$pivotTable])->where($permissionKey, $legacy->id)->get() as $assignment) {
                            $row = (array) $assignment;
                            $row[$permissionKey] = $target->getKey();
                            DB::table($tables[$pivotTable])->insertOrIgnore($row);
                        }
                    }
                }
            }

            $legacyIds = $legacyPermissions->pluck('id');
            foreach (['role_has_permissions', 'model_has_permissions'] as $pivotTable) {
                DB::table($tables[$pivotTable])->whereIn($permissionKey, $legacyIds)->delete();
            }
            DB::table($tables['permissions'])->whereIn('id', $legacyIds)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Removed permissions and assignments cannot be reconstructed on rollback.
     */
    public function down(): void {}
};
