<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class RoleSeeder extends Seeder
{
    /**
     * Custom, non-CRUD permissions that Shield's standard generator does not
     * know about (they aren't in config('filament-shield.policies.methods')),
     * so they're registered here to appear in the Roles permission matrix.
     */
    private const array CUSTOM_PERMISSIONS = [
        'Cancel:Repack',
        'ViewAny:Stock',
        'Update:Stock',
        'Export:Stock',
        'ViewAny:Invoice',
        'View:Invoice',
        'Create:Invoice',
        'Export:Invoice',
        'Delete:Invoice',
        'AdvanceStatus:Order',
        'Verify:Payment',
        'Reject:Payment',
        'Void:Payment',
        'Ship:StockMovement',
        'Receive:StockMovement',
        'RejectDispatch:StockMovement',
        'Cancel:StockMovement',
    ];

    public function run(): void
    {
        Role::findOrCreate('super_admin', 'web');

        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
            '--no-interaction' => true,
        ]);

        // Fine-grained financial/workflow actions that intentionally sit
        // outside the generic Update:Order / Update:Payment permission —
        // see OrderPolicy::advanceStatus() and PaymentPolicy::verify()/
        // reject() for why they're kept separate.
        foreach (self::CUSTOM_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

    }
}
