<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'ViewAny:Customer',
            'View:Customer',
            'Create:Customer',
            'Update:Customer',
            'Delete:Customer',
            'DeleteAny:Customer',
            'Restore:Customer',
            'RestoreAny:Customer',
            'Replicate:Customer',
            'Reorder:Customer',
            'Export:Customer',
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
