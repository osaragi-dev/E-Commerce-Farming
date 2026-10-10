<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'ViewAny:PurchaseOrder', 'View:PurchaseOrder', 'Create:PurchaseOrder', 'Update:PurchaseOrder',
            'Delete:PurchaseOrder', 'DeleteAny:PurchaseOrder', 'Restore:PurchaseOrder', 'RestoreAny:PurchaseOrder',
            'Replicate:PurchaseOrder', 'Reorder:PurchaseOrder', 'Export:PurchaseOrder',
            'ViewAny:PurchaseInvoice', 'View:PurchaseInvoice', 'Create:PurchaseInvoice', 'Update:PurchaseInvoice',
            'Delete:PurchaseInvoice', 'DeleteAny:PurchaseInvoice', 'Restore:PurchaseInvoice', 'RestoreAny:PurchaseInvoice',
            'Replicate:PurchaseInvoice', 'Reorder:PurchaseInvoice', 'Export:PurchaseInvoice',
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
