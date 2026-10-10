<?php

use App\Enums\ItemType;
use App\Enums\RecordStatus;
use App\Filament\Resources\Items\Pages\ManageItems;
use App\Models\Item;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);
});

test('items use the baseline lifecycle', function (): void {
    $record = Item::factory()->create();

    expect($record->id)->toBeString()
        ->and($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->markAsDraft())->toBeTrue()
        ->and($record->fresh()->record_status)->toBe(RecordStatus::Draft);
});

test('items can be created as saved records or drafts with required procurement fields', function (): void {
    $unit = Unit::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $data = [
        'code' => 'ITEM-100',
        'sku' => 'SKU-100',
        'name' => 'Fresh Chicken',
        'item_type' => ItemType::RawMaterial->value,
        'unit_id' => $unit->id,
        'initial_warehouse_id' => $warehouse->id,
        'initial_stock' => 10,
        'min_stock' => 2,
        'avg_purchase_price' => '25.000,00',
        'purchasable' => true,
        'is_active' => true,
        'shelf_life_days' => 3,
        'notes' => 'Keep refrigerated',
    ];

    Livewire::test(ManageItems::class)
        ->callAction('create', data: $data)
        ->assertHasNoActionErrors();

    $item = Item::query()->where('code', 'ITEM-100')->sole();

    expect($item->record_status)->toBe(RecordStatus::Saved)
        ->and($item->sku)->toBe('SKU-100')
        ->and($item->unit_id)->toBe($unit->id)
        ->and($item->initial_warehouse_id)->toBe($warehouse->id)
        ->and($item->avg_purchase_price)->toBe('25000.00')
        ->and($item->shelf_life_days)->toBe(3);

    Livewire::test(ManageItems::class)
        ->callAction('create', data: array_replace($data, [
            'code' => 'ITEM-101',
            'sku' => 'SKU-101',
        ]), arguments: ['draft' => true])
        ->assertHasNoActionErrors();

    expect(Item::query()->where('code', 'ITEM-101')->sole()->record_status)
        ->toBe(RecordStatus::Draft);
});

test('saved item codes and skus are unique and initial stock requires a location', function (): void {
    $unit = Unit::factory()->create();
    $data = [
        'code' => 'ITEM-200',
        'sku' => 'SKU-200',
        'name' => 'Fresh Herbs',
        'item_type' => ItemType::RawMaterial->value,
        'unit_id' => $unit->id,
        'initial_stock' => 0,
        'min_stock' => 0,
        'avg_purchase_price' => 0,
        'purchasable' => true,
        'is_active' => true,
    ];

    Livewire::test(ManageItems::class)
        ->callAction('create', data: $data)
        ->assertHasNoActionErrors();

    Livewire::test(ManageItems::class)
        ->callAction('create', data: array_replace($data, ['name' => 'Another Item']))
        ->assertHasActionErrors(['code']);

    Livewire::test(ManageItems::class)
        ->callAction('create', data: array_replace($data, [
            'code' => 'ITEM-201',
            'sku' => 'SKU-201',
            'initial_stock' => 1,
        ]))
        ->assertHasActionErrors(['initial_warehouse_id']);
});

test('items table exposes the requested procurement and audit columns', function (): void {
    $component = Livewire::test(ManageItems::class)
        ->assertOk();

    $labels = collect($component->instance()->getTable()->getColumns())
        ->map(fn ($column): string => $column->getLabel())
        ->all();

    expect($labels)->toContain(
        'No',
        'Code',
        'SKU',
        'Name',
        'Item Type',
        'Base Unit',
        'Min Stock',
        'Avg Purchase Price',
        'Purchasable',
        'Shelf Life (Days)',
        'Active',
        'Status',
        'Notes',
        'Created By',
        'Created At',
        'Updated By',
        'Updated At',
    )->and($component->instance()->getTable()->getRecordActionsColumnLabel())->toBe('Action');
});
