<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'ViewAny:Unit',
            'View:Unit',
            'Create:Unit',
            'Update:Unit',
            'Delete:Unit',
            'DeleteAny:Unit',
            'Restore:Unit',
            'RestoreAny:Unit',
            'Replicate:Unit',
            'Reorder:Unit',
            'Export:Unit',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Existing role assignments make deleting permissions unsafe.
    }
};
