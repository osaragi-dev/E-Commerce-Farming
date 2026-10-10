<?php

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Repacks\RepackResource;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Stocks\StockResource;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $superAdministrator = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create(['sso_id' => 999]);
    $this->actor->assignRole($superAdministrator);
    $this->actingAs($this->actor);
});

test('Repack permissions can be saved and reloaded while SSO is unavailable', function (): void {
    Http::preventStrayRequests();
    $role = Role::factory()->create(['sso_id' => 501]);
    $existingPermission = Permission::findOrCreate('ViewAny:Product', 'web');
    $role->givePermissionTo($existingPermission);
    $permissions = array_keys(FilamentShield::getResourcePermissionsWithLabels(RepackResource::class));

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([RepackResource::class => $permissions])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()->getPermissionNames()->all())->toContain(...$permissions)
        ->toContain($existingPermission->name);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->assertFormSet([RepackResource::class => $permissions]);
    Http::assertNothingSent();
});

test('role form exposes the complete Customer resource permission group', function (): void {
    expect(config('filament-shield.resources.exclude'))->not->toContain(CustomerResource::class)
        ->and(config('filament-shield.custom_permissions'))->not->toHaveKeys([
            'ViewAny:Customer',
            'View:Customer',
        ]);

    $permissions = FilamentShield::getResourcePermissionsWithLabels(CustomerResource::class);

    expect(array_keys($permissions))->toBe([
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
    ]);

    Livewire::test(CreateRole::class)
        ->assertFormFieldExists(CustomerResource::class);
});

test('role form exposes the complete Unit resource permission group', function (): void {
    expect(config('filament-shield.resources.exclude'))->not->toContain(UnitResource::class);

    $permissions = FilamentShield::getResourcePermissionsWithLabels(UnitResource::class);

    expect(array_keys($permissions))->toBe([
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
    ])
        ->and(Permission::query()->whereIn('name', array_keys($permissions))->where('guard_name', 'web')->count())
        ->toBe(count($permissions));

    $role = Role::create([
        'code' => 'unit_permission_role',
        'name' => 'Unit Permission Role',
        'guard_name' => 'web',
    ]);

    Livewire::test(CreateRole::class)
        ->assertFormFieldExists(UnitResource::class);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->assertFormFieldExists(UnitResource::class);
});

test('role can be assigned Unit resource permissions', function (): void {
    Http::preventStrayRequests();

    Livewire::test(CreateRole::class)
        ->fillForm([
            'code' => 'unit_manager',
            'name' => 'Unit Manager',
            UnitResource::class => ['ViewAny:Unit', 'View:Unit', 'Create:Unit'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('code', 'unit_manager')->firstOrFail();

    expect($role->hasPermissionTo('ViewAny:Unit'))->toBeTrue()
        ->and($role->hasPermissionTo('View:Unit'))->toBeTrue()
        ->and($role->hasPermissionTo('Create:Unit'))->toBeTrue();
    Http::assertNothingSent();

});

test('role create form shows code before name and hides guard name', function (): void {
    expect(config('filament-shield.resources.manage'))->toHaveKey(RoleResource::class);

    Livewire::test(CreateRole::class)
        ->assertOk()
        ->assertSeeInOrder(['Code', 'Name'])
        ->assertFormFieldExists('code')
        ->assertFormFieldExists('name')
        ->assertFormFieldExists(
            'guard_name',
            fn (mixed $field): bool => $field instanceof Hidden && $field->getState() === 'web',
        )
        ->assertDontSee('Guard Name');
});

test('role table shows code before name and hides guard name', function (): void {
    $component = Livewire::test(ListRoles::class)
        ->assertOk()
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('name')
        ->assertTableColumnDoesNotExist('guard_name');

    expect(array_slice(array_keys($component->instance()->getTable()->getColumns()), 0, 3))
        ->toBe(['row_number', 'code', 'name']);
});

test('role table orders audit columns by timestamp then actor', function (): void {
    $table = Livewire::test(ListRoles::class)->instance()->getTable();
    $auditColumns = [
        'created_at',
        'createdBy.name',
        'updated_at',
        'updatedBy.name',
    ];

    expect(array_values(array_intersect(array_keys($table->getColumns()), $auditColumns)))
        ->toBe($auditColumns)
        ->and($table->getColumn('updated_at')->getPlaceholder())->toBe('-')
        ->and($table->getColumn('updatedBy.name')->getPlaceholder())->toBe('-');
});

test('role table uses the shared baseline actions exports and column manager', function (): void {
    $component = Livewire::test(ListRoles::class)
        ->assertOk()
        ->assertTableActionExists('clone')
        ->assertTableActionDoesNotExist('restore')
        ->assertTableActionExists('exportCsv')
        ->assertTableBulkActionExists('exportSelectedCsv')
        ->assertSee('Show Entries')
        ->assertSeeInOrder(['Show Entries', 'Export']);
    $table = $component->instance()->getTable();

    expect($table->hasDeferredColumnManager())->toBeFalse()
        ->and($table->getColumn('row_number'))->not->toBeNull()
        ->and($table->getColumn('record_status'))->toBeNull()
        ->and($table->getColumn('code')->isToggleable())->toBeFalse()
        ->and($table->getColumn('name')->isToggleable())->toBeTrue();
});

test('role can be cloned with reviewed permissions', function (): void {
    Http::preventStrayRequests();

    $permission = Permission::findOrCreate('ViewAny:Product', 'web');
    $source = Role::create([
        'code' => 'source_role',
        'name' => 'Source Role',
        'guard_name' => 'web',
    ]);
    $source->givePermissionTo($permission);

    Livewire::test(ListRoles::class)
        ->callTableAction('clone', $source, data: [
            'code' => 'cloned_role',
            'name' => 'Cloned Role',
            'guard_name' => 'api',
            ProductResource::class => [$permission->name],
        ])
        ->assertHasNoActionErrors();

    $clone = Role::query()->where('code', 'cloned_role')->firstOrFail();

    expect($clone->id)->not->toBe($source->id)
        ->and($clone->guard_name)->toBe('web')
        ->and($clone->hasPermissionTo($permission))->toBeTrue();
    Http::assertNothingSent();

});

test('role can be created with code name permissions and an implicit web guard', function (): void {
    Http::preventStrayRequests();

    Livewire::test(CreateRole::class)
        ->fillForm([
            'code' => 'inventory_manager',
            'name' => 'Inventory Manager',
            'guard_name' => 'api',
            ProductResource::class => ['ViewAny:Product'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('code', 'inventory_manager')->firstOrFail();

    expect($role->name)->toBe('Inventory Manager')
        ->and($role->guard_name)->toBe('web')
        ->and($role->hasPermissionTo('ViewAny:Product'))->toBeTrue();
    Http::assertNothingSent();

});

test('new roles only record created audit metadata', function (): void {
    Http::preventStrayRequests();

    Livewire::test(CreateRole::class)
        ->fillForm([
            'code' => 'audit_role',
            'name' => 'Audit Role',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('code', 'audit_role')->firstOrFail();

    expect($role->created_by)->toBe($this->actor->getKey())
        ->and($role->created_at)->not->toBeNull()
        ->and($role->updated_by)->toBeNull()
        ->and($role->updated_at)->toBeNull();
    Http::assertNothingSent();

});

test('editing a role records updated audit metadata together', function (): void {
    Http::preventStrayRequests();

    $this->freezeTime();

    $role = Role::create([
        'code' => 'editable_role',
        'name' => 'Editable Role',
        'guard_name' => 'web',
    ]);

    $this->travel(1)->minute();

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            'code' => 'edited_role',
            'name' => 'Edited Role',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $role->refresh();

    expect($role->updated_by)->toBe($this->actor->getKey())
        ->and($role->updated_at)->not->toBeNull()
        ->and($role->updated_at->greaterThan($role->created_at))->toBeTrue();
    Http::assertNothingSent();

});

test('editing role permissions records updated audit metadata', function (): void {
    Http::preventStrayRequests();

    $this->freezeTime();

    $oldPermission = Permission::findOrCreate('ViewAny:Product', 'web');
    $newPermission = Permission::findOrCreate('View:Product', 'web');
    $role = Role::create([
        'code' => 'permission_editor',
        'name' => 'Permission Editor',
        'guard_name' => 'web',
    ]);
    $role->givePermissionTo($oldPermission);

    $this->travel(1)->minute();

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            ProductResource::class => [$newPermission->name],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $role->refresh();

    expect($role->hasPermissionTo($newPermission))->toBeTrue()
        ->and($role->hasPermissionTo($oldPermission))->toBeFalse()
        ->and($role->updated_by)->toBe($this->actor->getKey())
        ->and($role->updated_at)->not->toBeNull()
        ->and($role->updated_at->greaterThan($role->created_at))->toBeTrue();
    Http::assertNothingSent();

});

test('saving an unchanged role permission set does not update audit metadata', function (): void {
    Http::preventStrayRequests();

    $permission = Permission::findOrCreate('ViewAny:Product', 'web');
    $role = Role::create([
        'sso_id' => 501,
        'code' => 'unchanged_permissions',
        'name' => 'Unchanged Permissions',
        'guard_name' => 'web',
    ]);
    $role->givePermissionTo($permission);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            ProductResource::class => [$permission->name],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->updated_by)->toBeNull()
        ->and($role->updated_at)->toBeNull();
    Http::assertNothingSent();

});

test('role updates without a valid actor do not create partial audit metadata', function (): void {
    $role = Role::create([
        'code' => 'system_role',
        'name' => 'System Role',
        'guard_name' => 'web',
    ]);

    auth('web')->logout();
    $updated = $role->update(['name' => 'Updated System Role']);
    $role->refresh();

    expect($updated)->toBeFalse()
        ->and($role->name)->toBe('System Role')
        ->and($role->updated_by)->toBeNull()
        ->and($role->updated_at)->toBeNull();
});

test('role code and name can be edited', function (): void {
    Http::preventStrayRequests();

    $role = Role::create([
        'code' => 'stock_keeper',
        'name' => 'Stock Keeper',
        'guard_name' => 'web',
    ]);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            'code' => 'warehouse_manager',
            'name' => 'Warehouse Manager',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->only(['code', 'name', 'guard_name']))->toBe([
        'code' => 'warehouse_manager',
        'name' => 'Warehouse Manager',
        'guard_name' => 'web',
    ]);
    Http::assertNothingSent();

});

test('role code is required and unique', function (): void {
    Role::create([
        'code' => 'auditor',
        'name' => 'Existing Auditor',
        'guard_name' => 'web',
    ]);

    Livewire::test(CreateRole::class)
        ->fillForm([
            'code' => null,
            'name' => 'Missing Code',
        ])
        ->call('create')
        ->assertHasFormErrors(['code' => 'required'])
        ->fillForm([
            'code' => 'auditor',
            'name' => 'Duplicate Code',
        ])
        ->call('create')
        ->assertHasFormErrors(['code' => 'unique']);
});

test('programmatic role creation defaults code to name', function (): void {
    $role = Role::findOrCreate('auditor', 'web');

    expect($role->code)->toBe('auditor')
        ->and($role->guard_name)->toBe('web');
});

test('role form follows sidebar order and describes modules without model paths', function (): void {
    Livewire::test(CreateRole::class)
        ->assertSeeInOrder([
            'Organize products by business sector.',
            'Group products into categories.',
            'Define units used for product quantities and sales.',
            'Manage products, variants, pricing, and availability.',
            'Manage storage locations and warehouse settings.',
            'Manage customer profiles and contact details.',
            'Choose which products appear on the storefront.',
            'Review available stock and update inventory quantities.',
            'Dispatch, receive, reject, and cancel warehouse transfers.',
            'Count physical inventory and reconcile stock differences.',
            'Record inventory corrections and their reasons.',
            'Review stock movements and inventory transaction history.',
            'Manage orders, fulfillment, and invoices.',
            'Review payments, verify proof, and handle rejected or voided payments.',
            'Manage back-office role assignments and user impersonation.',
            'Configure roles and access permissions for each module.',
        ])
        ->assertDontSee('App\\Models\\ApprovalSetting')
        ->assertDontSee('Custom Permissions')
        ->assertDontSee('Manage stock records for individual warehouses.');
});

test('workflow permissions appear once under their owning modules', function (): void {
    $component = Livewire::test(CreateRole::class);
    foreach ([
        OrderResource::class => ['AdvanceStatus:Order', 'ViewAny:Invoice', 'Create:Invoice', 'Delete:Invoice'],
        PaymentResource::class => ['Verify:Payment', 'Reject:Payment', 'Void:Payment'],
        StockMovementResource::class => ['Ship:StockMovement', 'Receive:StockMovement', 'RejectDispatch:StockMovement', 'Cancel:StockMovement'],
        StockResource::class => ['ViewAny:Stock', 'Update:Stock', 'Export:Stock'],
    ] as $resource => $permissions) {
        $component->assertFormFieldExists($resource, fn (CheckboxList $field): bool => array_diff($permissions, array_keys($field->getOptions())) === []);
    }

    $options = collect($component->instance()->form->getFlatComponents())
        ->filter(fn ($field): bool => $field instanceof CheckboxList)
        ->flatMap(fn (CheckboxList $field): array => array_keys($field->getOptions()));
    foreach (array_keys(FilamentShield::getCustomPermissions()) as $permission) {
        expect($options->filter(fn (string $option): bool => $option === $permission)->count())->toBe(1);
    }
    expect($options->filter(fn (string $option): bool => str_ends_with($option, ':WarehouseStock')))->toBeEmpty();
});

test('editing a role preserves regrouped permissions and allows revocation', function (): void {
    Http::preventStrayRequests();
    $role = Role::factory()->create(['sso_id' => 501, 'code' => 'WORKFLOW', 'name' => 'Workflow']);
    foreach (['Verify:Payment', 'Create:Invoice', 'Update:Stock'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $component = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->assertFormSet([
            PaymentResource::class => ['Verify:Payment'],
            OrderResource::class => ['Create:Invoice'],
            StockResource::class => ['Update:Stock'],
        ])->call('save')->assertHasNoFormErrors();
    expect($role->fresh()->getPermissionNames()->sort()->values()->all())
        ->toBe(['Create:Invoice', 'Update:Stock', 'Verify:Payment']);

    $component->fillForm([PaymentResource::class => []])->call('save')->assertHasNoFormErrors();
    expect($role->fresh()->getPermissionNames()->sort()->values()->all())->toBe(['Create:Invoice', 'Update:Stock']);
});

test('cloning a role retains permissions moved into module sections', function (): void {
    Http::preventStrayRequests();
    $source = Role::factory()->create();
    foreach (['Verify:Payment', 'Create:Invoice', 'Update:Stock'] as $permission) {
        $source->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    Livewire::test(ListRoles::class)->callTableAction('clone', $source, data: [
        'code' => 'WORKFLOW_COPY', 'name' => 'Workflow Copy',
    ])->assertHasNoActionErrors();
    expect(Role::where('code', 'WORKFLOW_COPY')->sole()->getPermissionNames()->sort()->values()->all())
        ->toBe(['Create:Invoice', 'Update:Stock', 'Verify:Payment']);
});
