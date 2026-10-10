<?php

use App\Enums\RecordStatus;
use App\Enums\StockAdjustmentType;
use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Filament\Actions\TableExportActions;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Resources\Stocks\StockResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

test('every admin resource index requires its view permission', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('auditor', 'web'));
    $this->actingAs($user);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! array_key_exists('index', $resource::getPages())) {
            continue;
        }

        $path = parse_url($resource::getUrl('index'), PHP_URL_PATH);
        expect($this->get($path)->status())->toBe(403, $resource);
    }
});

test('granted and super admin users can open every admin resource index', function (): void {
    $resources = Filament::getPanel('admin')->getResources();
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('auditor', 'web'));

    foreach ($resources as $resource) {
        $subject = $resource === StockResource::class
            ? 'Stock'
            : class_basename($resource::getModel());

        foreach (['ViewAny', 'View', 'Update'] as $ability) {
            Permission::findOrCreate("{$ability}:{$subject}", 'web');
            $user->givePermissionTo("{$ability}:{$subject}");
        }
    }

    $this->actingAs($user);

    foreach ($resources as $resource) {
        if (! array_key_exists('index', $resource::getPages())) {
            continue;
        }

        $path = parse_url($resource::getUrl('index'), PHP_URL_PATH);
        expect($this->get($path)->status())->toBeIn([200, 302], $resource);
    }

    $superAdmin = User::factory()->sso()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($superAdmin);

    foreach ($resources as $resource) {
        if (! array_key_exists('index', $resource::getPages())) {
            continue;
        }

        $path = parse_url($resource::getUrl('index'), PHP_URL_PATH);
        expect($this->get($path)->status())->toBeIn([200, 302], $resource);
    }
});

test('inventory CRUD requires both the matching permission and existing record rules', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $movement = StockMovement::factory()->create([
        'created_by' => $user->id,
        'status' => StockMovementStatus::Draft,
        'approval_status' => StockMovementApprovalStatus::Pending,
    ]);
    $opname = StockOpnameSession::factory()->create(['created_by' => $user->id]);
    $adjustment = StockAdjustment::factory()->create([
        'adjusted_by' => $user->id,
        'type' => StockAdjustmentType::Tambah,
        'quantity' => 1,
        'record_status' => RecordStatus::Draft,
    ]);

    foreach ([
        [StockMovementResource::class, $movement, 'StockMovement'],
        [StockOpnameResource::class, $opname, 'StockOpnameSession'],
        [StockAdjustmentResource::class, $adjustment, 'StockAdjustment'],
    ] as [$resource, $record, $subject]) {
        expect($resource::canEdit($record))->toBeFalse();
        Permission::findOrCreate("Update:{$subject}", 'web');
        $user->givePermissionTo("Update:{$subject}");
        expect($resource::canEdit($record))->toBeTrue($resource);
    }

    foreach ([
        [StockMovementResource::class, $movement, 'StockMovement'],
        [StockOpnameResource::class, $opname, 'StockOpnameSession'],
    ] as [$resource, $record, $subject]) {
        expect($resource::canDelete($record))->toBeFalse();
        Permission::findOrCreate("Delete:{$subject}", 'web');
        $user->givePermissionTo("Delete:{$subject}");
        expect($resource::canDelete($record))->toBeTrue();

        $record->delete();
        expect($resource::canRestore($record))->toBeFalse();
        Permission::findOrCreate("Restore:{$subject}", 'web');
        $user->givePermissionTo("Restore:{$subject}");
        expect($resource::canRestore($record))->toBeTrue();
    }
});

test('every resource export checks its own export permission for page and selected rows', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (! is_subclass_of($resource, HasTableExport::class)) {
            continue;
        }

        $group = TableExportActions::group($resource);
        $selected = TableExportActions::selected($resource);

        expect($group->isVisible())->toBeFalse($resource);
        foreach ($selected as $action) {
            expect($action->isAuthorized())->toBeFalse($resource);
        }

        $subject = class_basename($resource::getModel());
        Permission::findOrCreate("Export:{$subject}", 'web');
        $user->givePermissionTo("Export:{$subject}");

        expect($group->isVisible())->toBeTrue($resource);
        foreach ($selected as $action) {
            expect($action->isAuthorized())->toBeTrue($resource);
        }

        $user->revokePermissionTo("Export:{$subject}");
    }
});

test('stock movement dispatch actions require separate permissions on table and detail', function (string $action, string $permission, StockMovementStatus $status, StockMovementApprovalStatus $approval): void {
    foreach (['ViewAny:StockMovement', 'View:StockMovement', $permission] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo(['ViewAny:StockMovement', 'View:StockMovement']);
    $this->actingAs($user);

    $movement = StockMovement::factory()->create([
        'status' => $status,
        'approval_status' => $approval,
        'reserved_at' => $action === 'cancel' ? now() : null,
    ]);

    if ($action !== 'cancel') {
        Livewire::test(ListStockMovements::class)->assertTableActionHidden($action, $movement);
    }
    Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])->assertActionHidden($action);

    $user->givePermissionTo($permission);

    if ($action !== 'cancel') {
        Livewire::test(ListStockMovements::class)->assertTableActionVisible($action, $movement);
    }
    Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])->assertActionVisible($action);
})->with([
    'ship' => ['ship', 'Ship:StockMovement', StockMovementStatus::Approved, StockMovementApprovalStatus::Approved],
    'receive' => ['receive', 'Receive:StockMovement', StockMovementStatus::Shipped, StockMovementApprovalStatus::Approved],
    'reject dispatch' => ['rejectDispatch', 'RejectDispatch:StockMovement', StockMovementStatus::Shipped, StockMovementApprovalStatus::Approved],
    'cancel' => ['cancel', 'Cancel:StockMovement', StockMovementStatus::Requested, StockMovementApprovalStatus::Requested],
]);

test('new stock movement permissions are not assigned to existing roles', function (): void {
    $role = Role::findOrCreate('warehouse_operator', 'web');

    foreach (['Ship', 'Receive', 'RejectDispatch', 'Cancel'] as $ability) {
        $permission = Permission::findOrCreate("{$ability}:StockMovement", 'web');
        expect($role->hasPermissionTo($permission))->toBeFalse();
    }
});

test('role seeding registers inventory permissions without granting existing roles or replacing policies', function (): void {
    $role = Role::findOrCreate('warehouse_operator', 'web');

    app(RoleSeeder::class)->run();

    foreach ([
        'Ship:StockMovement',
        'Receive:StockMovement',
        'RejectDispatch:StockMovement',
        'Cancel:StockMovement',
        'Update:StockMovement',
        'Delete:StockMovement',
        'Restore:StockMovement',
        'Update:StockOpnameSession',
        'Delete:StockOpnameSession',
        'Restore:StockOpnameSession',
    ] as $name) {
        expect(Permission::query()->where('name', $name)->exists())->toBeTrue($name);
        expect($role->hasPermissionTo($name))->toBeFalse($name);
    }

    expect(method_exists(OrderPolicy::class, 'advanceStatus'))->toBeTrue()
        ->and(method_exists(PaymentPolicy::class, 'verify'))->toBeTrue();
});
