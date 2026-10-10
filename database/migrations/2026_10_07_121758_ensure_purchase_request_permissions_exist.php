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
            'ViewAny:PurchaseRequest',
            'View:PurchaseRequest',
            'Create:PurchaseRequest',
            'Update:PurchaseRequest',
            'Delete:PurchaseRequest',
            'DeleteAny:PurchaseRequest',
            'Restore:PurchaseRequest',
            'RestoreAny:PurchaseRequest',
            'Replicate:PurchaseRequest',
            'Reorder:PurchaseRequest',
            'Export:PurchaseRequest',
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
