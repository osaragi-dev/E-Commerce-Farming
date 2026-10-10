<?php

use App\Enums\RecordStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Warehouses\Pages\ManageWarehouses;
use App\Filament\Resources\Warehouses\Schemas\WarehouseInfolist;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

test('warehouses use the baseline lifecycle', function (): void {
    $record = Warehouse::factory()->create(['code' => 'WH-MAIN']);

    expect($record->id)->toBeString()
        ->and($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->markAsDraft())->toBeTrue()
        ->and($record->fresh()->record_status)->toBe(RecordStatus::Draft);
});

test('warehouses appear between units and customers in master data', function (): void {
    expect(WarehouseResource::getNavigationGroup())->toBe('Master Data')
        ->and(UnitResource::getNavigationSort())->toBeLessThan(ProductResource::getNavigationSort())
        ->and(ProductResource::getNavigationSort())->toBeLessThan(WarehouseResource::getNavigationSort())
        ->and(UnitResource::getNavigationSort())->toBeLessThan(WarehouseResource::getNavigationSort())
        ->and(WarehouseResource::getNavigationSort())->toBeLessThan(CustomerResource::getNavigationSort());
});

test('warehouse form and table expose the POS flag without address', function (): void {
    Livewire::test(ManageWarehouses::class)
        ->mountAction('create')
        ->assertFormFieldExists('is_pos')
        ->assertFormFieldDoesNotExist('address')
        ->assertFormFieldDoesNotExist('fulfillment_priority')
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('is_pos')
        ->assertTableColumnDoesNotExist('fulfillment_priority');
});

test('only one active saved warehouse can be POS', function (): void {
    Warehouse::factory()->create(['is_pos' => true]);

    expect(fn (): Warehouse => Warehouse::factory()->create(['is_pos' => true]))
        ->toThrow(ValidationException::class);
});

test('fulfillment candidates include only the active POS warehouse', function (): void {
    $pos = Warehouse::factory()->create([
        'code' => 'WH-POS-TEST',
        'is_pos' => true,
    ]);
    $fallback = Warehouse::factory()->create(['code' => 'WH-FALLBACK-TEST']);

    expect(Warehouse::fulfillmentCandidates()
        ->whereKey([$pos->id, $fallback->id])
        ->pluck('id')
        ->all())->toBe([$pos->id]);
});

test('warehouse table does not expose the created date filter', function (): void {
    $filters = Livewire::test(ManageWarehouses::class)
        ->instance()
        ->getTable()
        ->getFilters();

    expect($filters)->not->toHaveKey('created_at');
});

test('warehouse table places audit timestamps before users', function (): void {
    $columns = array_map(
        fn ($column): string => $column->getName(),
        Livewire::test(ManageWarehouses::class)->instance()->getTable()->getColumns(),
    );

    expect(array_values(array_intersect($columns, [
        'created_at',
        'createdBy.name',
        'updated_at',
        'updatedBy.name',
        'deleted_at',
        'deletedBy.name',
    ])))->toBe([
        'created_at',
        'createdBy.name',
        'updated_at',
        'updatedBy.name',
        'deleted_at',
        'deletedBy.name',
    ]);
});

test('warehouse details and exports keep the requested audit order', function (): void {
    $livewire = Livewire::test(ManageWarehouses::class)->instance();
    $schema = WarehouseInfolist::configure(Schema::make($livewire));
    $warehouse = collect($schema->getComponents())
        ->first(fn ($component): bool => $component->getHeading() === 'Warehouse');
    $audit = collect($schema->getComponents())
        ->first(fn ($component): bool => $component->getHeading() === 'Audit');
    $warehouseNames = array_map(
        fn ($component): ?string => $component->getName(),
        $warehouse->getChildComponents(),
    );
    $auditNames = array_map(
        fn ($component): ?string => $component->getName(),
        $audit->getChildComponents(),
    );
    $exportColumns = WarehouseResource::exportDefinition()->columns;

    expect($auditNames)->toBe([
        'record_status',
        'created_at',
        'createdBy.name',
        'updated_at',
        'updatedBy.name',
        'deleted_at',
        'deletedBy.name',
    ])
        ->and($warehouseNames)->not->toContain('address')
        ->and($warehouseNames)->toContain('is_pos')
        ->and($exportColumns)->not->toHaveKey('address')
        ->and($exportColumns)->toHaveKey('is_pos', 'Is POS')
        ->and(array_values(array_intersect(
            array_keys($exportColumns),
            ['created_at', 'createdBy.name', 'updated_at', 'updatedBy.name', 'deleted_at', 'deletedBy.name'],
        )))->toBe([
            'created_at',
            'createdBy.name',
            'updated_at',
            'updatedBy.name',
            'deleted_at',
            'deletedBy.name',
        ]);
});
