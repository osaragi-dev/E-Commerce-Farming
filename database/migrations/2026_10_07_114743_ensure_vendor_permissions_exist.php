<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ([
            'ViewAny:Vendor',
            'View:Vendor',
            'Create:Vendor',
            'Update:Vendor',
            'Delete:Vendor',
            'DeleteAny:Vendor',
            'Restore:Vendor',
            'RestoreAny:Vendor',
            'Replicate:Vendor',
            'Reorder:Vendor',
            'Export:Vendor',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing role assignments make deleting permissions unsafe.
    }
};
