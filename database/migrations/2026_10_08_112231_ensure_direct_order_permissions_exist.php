<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'ViewAny:DirectOrder',
            'View:DirectOrder',
            'Create:DirectOrder',
            'Update:DirectOrder',
            'Delete:DirectOrder',
            'DeleteAny:DirectOrder',
            'Restore:DirectOrder',
            'RestoreAny:DirectOrder',
            'Replicate:DirectOrder',
            'Reorder:DirectOrder',
            'Export:DirectOrder',
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
