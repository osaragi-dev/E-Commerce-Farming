<?php

use App\Enums\RecordStatus;
use App\Enums\StockMutationSource;
use App\Enums\StockWasteApprovalStatus;
use App\Enums\StockWasteSource;
use App\Enums\StockWasteStatus;
use App\Enums\StockWasteType;
use App\Filament\Resources\StockWastes\Pages\ListStockWastes;
use App\Filament\Resources\StockWastes\Pages\ViewStockWaste;
use App\Filament\Resources\StockWastes\StockWasteResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockMutation;
use App\Models\StockWaste;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('waste numbers use a daily padded sequence', function (): void {
    $first = StockWaste::factory()->create();
    $second = StockWaste::factory()->create();
    $prefix = 'WST-'.now()->format('Ymd').'-';

    expect($first->waste_number)->toBe($prefix.'001')
        ->and($second->waste_number)->toBe($prefix.'002');
});

test('posting approved stock waste reduces its location and aggregate stock once', function (): void {
    $record = StockWaste::factory()->create([
        'quantity' => '5.000',
        'record_status' => RecordStatus::Saved,
        'approval_status' => StockWasteApprovalStatus::Approved,
    ]);
    $variant = $record->productVariant;
    $variant->forceFill(['stock_available' => '20.000'])->saveQuietly();
    WarehouseStock::query()->create([
        'warehouse_id' => $record->warehouse_id,
        'product_variant_id' => $variant->id,
        'stock_available' => '20.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);

    expect($variant->fresh()->stock_available)->toBe('20.000')
        ->and($record->isPostable())->toBeTrue()
        ->and($record->source)->toBe(StockWasteSource::Item)
        ->and($record->waste_status)->toBe(StockWasteStatus::Saved)
        ->and($record->picUser)->not->toBeNull();

    $record->post();
    $record->post();

    expect($variant->fresh()->stock_available)->toBe('15.000')
        ->and($variant->fresh()->stock_out)->toBe('5.000')
        ->and(WarehouseStock::query()->where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $variant->id)->value('stock_available'))->toBe('15.000')
        ->and(WarehouseStock::query()->where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $variant->id)->value('stock_out'))->toBe('5.000')
        ->and($record->fresh()->approval_status)->toBe(StockWasteApprovalStatus::Posted)
        ->and(StockMutation::query()->where('source', StockMutationSource::StockWaste)->where('reference_id', $record->id)->count())->toBe(1);
});

test('draft or unapproved stock waste cannot be posted', function (): void {
    $record = StockWaste::factory()->create([
        'record_status' => RecordStatus::Draft,
        'approval_status' => StockWasteApprovalStatus::Pending,
    ]);

    expect($record->isPostable())->toBeFalse()
        ->and(fn () => $record->post())->toThrow(LogicException::class);
});

test('creating a waste record saves it and posts stock immediately', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '10.000']);
    WarehouseStock::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => '10.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);

    $page = Livewire::test(ListStockWastes::class)
        ->callAction('create', data: [
            'warehouse_id' => $warehouse->id,
            'waste_date' => today()->toDateString(),
            'pic_user_id' => $actor->id,
            'product_variant_id' => $variant->id,
            'quantity' => '2.000',
            'waste_type' => StockWasteType::Damaged->value,
            'reason' => 'Damaged packaging',
        ])
        ->assertHasNoActionErrors();

    $record = StockWaste::query()->sole();

    expect($record->waste_status)->toBe(StockWasteStatus::Saved)
        ->and($record->source)->toBe(StockWasteSource::Item)
        ->and($record->posted_at)->not->toBeNull()
        ->and($variant->fresh()->stock_available)->toBe('8.000')
        ->and($variant->fresh()->stock_out)->toBe('2.000')
        ->and($record->stockMutation)->not->toBeNull()
        ->and($page->instance()->getTable()->getRecordUrl($record))->toBe(StockWasteResource::getUrl('view', ['record' => $record]));
});

test('creating a waste draft does not post or move stock', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '10.000']);
    WarehouseStock::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => '10.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);

    Livewire::test(ListStockWastes::class)
        ->callAction('create', data: [
            'warehouse_id' => $warehouse->id,
            'waste_date' => today()->toDateString(),
            'pic_user_id' => $actor->id,
            'product_variant_id' => $variant->id,
            'quantity' => '2.000',
            'waste_type' => StockWasteType::Damaged->value,
            'reason' => 'Draft waste',
        ], arguments: ['draft' => true])
        ->assertHasNoActionErrors();

    $record = StockWaste::query()->sole();

    expect($record->record_status)->toBe(RecordStatus::Draft)
        ->and($record->getRawOriginal('waste_status'))->toBe('draft')
        ->and($record->approval_status)->toBe(StockWasteApprovalStatus::Pending)
        ->and($record->posted_at)->toBeNull()
        ->and($variant->fresh()->stock_available)->toBe('10.000')
        ->and($variant->fresh()->stock_out)->toBe('0.000')
        ->and($record->stockMutation)->toBeNull();
});

test('waste table shows lifecycle audit, sortable unit and item separator without date filter', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $product = Product::factory()->create(['name' => 'Ayam']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'name' => 'Ayam',
    ]);
    $record = StockWaste::factory()->create(['product_variant_id' => $variant->id]);
    $draft = StockWaste::factory()->create(['record_status' => RecordStatus::Draft]);

    $page = Livewire::test(ListStockWastes::class)
        ->assertTableColumnExists('record_status')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updatedBy.name')
        ->assertTableColumnExists('deleted_at')
        ->assertTableColumnExists('deletedBy.name')
        ->assertTableColumnStateSet('productVariant.name', 'Ayam - Ayam', $record);

    $table = $page->instance()->getTable();

    expect($table->getColumn('productVariant.unit.name')->isSortable())->toBeTrue()
        ->and($table->getFilters())->not->toHaveKey('waste_date')
        ->and(array_search('created_at', array_keys($table->getColumns()), true))
        ->toBeLessThan(array_search('createdBy.name', array_keys($table->getColumns()), true))
        ->and(array_search('updated_at', array_keys($table->getColumns()), true))
        ->toBeLessThan(array_search('updatedBy.name', array_keys($table->getColumns()), true))
        ->and(array_search('deleted_at', array_keys($table->getColumns()), true))
        ->toBeLessThan(array_search('deletedBy.name', array_keys($table->getColumns()), true));

    Livewire::test(ListStockWastes::class)
        ->assertTableActionVisible('edit', record: $draft)
        ->assertTableActionVisible('delete', record: $draft)
        ->assertTableActionHidden('edit', record: $record)
        ->assertTableActionHidden('delete', record: $record);
});

test('stock waste form scopes items and quantity to the selected warehouse', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $availableVariant = ProductVariant::factory()->create(['stock_available' => '20.000']);
    $otherLocationVariant = ProductVariant::factory()->create(['stock_available' => '20.000']);
    WarehouseStock::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $availableVariant->id,
        'stock_available' => '4.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);
    WarehouseStock::query()->create([
        'warehouse_id' => $otherWarehouse->id,
        'product_variant_id' => $otherLocationVariant->id,
        'stock_available' => '8.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);

    $page = Livewire::test(ListStockWastes::class)->mountAction('create');
    $schemaName = $page->instance()->getMountedActionSchemaName();
    $page->setActionData(['warehouse_id' => $warehouse->id]);
    $schema = $page->instance()->getSchema($schemaName);

    expect($schema->getComponent('product_variant_id')->getOptions())
        ->toHaveKey($availableVariant->id)
        ->not->toHaveKey($otherLocationVariant->id)
        ->and($schema->getComponent('waste_type')->getOptions())
        ->not->toHaveKey(StockWasteType::Overproduction->value);

    $page->setActionData([
        'warehouse_id' => $warehouse->id,
        'waste_date' => today()->toDateString(),
        'pic_user_id' => $actor->id,
        'product_variant_id' => $availableVariant->id,
        'quantity' => '5.000',
        'waste_type' => StockWasteType::Damaged->value,
    ])->callMountedAction()->assertHasActionErrors(['quantity' => 'max']);

    expect(StockWaste::query()->count())->toBe(0)
        ->and(StockMutation::query()->where('source', StockMutationSource::StockWaste)->count())->toBe(0)
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $availableVariant->id)
            ->value('stock_available'))->toBe('4.000');
});

test('draft waste can be edited and deleted without posting stock', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '10.000']);
    WarehouseStock::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => '10.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);
    $draft = StockWaste::factory()->create([
        'record_status' => RecordStatus::Draft,
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'pic_user_id' => $actor->id,
        'reason' => 'Before edit',
    ]);

    Livewire::test(ListStockWastes::class)
        ->callTableAction('edit', $draft, data: [
            'warehouse_id' => $warehouse->id,
            'waste_date' => today()->toDateString(),
            'pic_user_id' => $actor->id,
            'product_variant_id' => $variant->id,
            'quantity' => '2.000',
            'waste_type' => StockWasteType::Damaged->value,
            'reason' => 'After edit',
        ], arguments: ['draft' => true])
        ->assertHasNoTableActionErrors();

    expect($draft->fresh()->record_status)->toBe(RecordStatus::Draft)
        ->and($draft->fresh()->reason)->toBe('After edit')
        ->and($draft->fresh()->posted_at)->toBeNull()
        ->and($variant->fresh()->stock_available)->toBe('10.000');

    Livewire::test(ListStockWastes::class)
        ->callTableAction('delete', $draft)
        ->assertHasNoTableActionErrors();

    expect($draft->fresh()->trashed())
        ->toBeTrue()
        ->and($draft->fresh()->record_status)->toBe(RecordStatus::Deleted);
});

test('cloning a waste record creates a fresh posted record', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '10.000']);
    WarehouseStock::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => '10.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);
    $source = StockWaste::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'quantity' => '2.000',
    ]);
    $source->post($actor->id);

    Livewire::test(ListStockWastes::class)
        ->callTableAction('clone', $source, data: [
            'warehouse_id' => $warehouse->id,
            'waste_date' => today()->toDateString(),
            'pic_user_id' => $actor->id,
            'product_variant_id' => $variant->id,
            'quantity' => '3.000',
            'waste_type' => StockWasteType::Damaged->value,
            'reason' => 'Cloned waste record',
        ])
        ->assertHasNoTableActionErrors();

    $clone = StockWaste::query()->whereKeyNot($source->id)->sole();

    expect($clone->waste_number)->not->toBe($source->waste_number)
        ->and($clone->record_status)->toBe(RecordStatus::Saved)
        ->and($clone->waste_status)->toBe(StockWasteStatus::Saved)
        ->and($clone->posted_at)->not->toBeNull()
        ->and($variant->fresh()->stock_available)->toBe('5.000')
        ->and(StockMutation::query()->where('reference_id', $clone->id)->where('source', StockMutationSource::StockWaste)->count())->toBe(1);
});

test('cloning a waste record as a draft does not post or move stock', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '10.000']);
    WarehouseStock::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => '10.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);
    $source = StockWaste::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
    ]);

    Livewire::test(ListStockWastes::class)
        ->callTableAction('clone', $source, data: [
            'warehouse_id' => $warehouse->id,
            'waste_date' => today()->toDateString(),
            'pic_user_id' => $actor->id,
            'product_variant_id' => $variant->id,
            'quantity' => '3.000',
            'waste_type' => StockWasteType::Damaged->value,
        ], arguments: ['draft' => true])
        ->assertHasNoTableActionErrors();

    $clone = StockWaste::query()->whereKeyNot($source->id)->sole();

    expect($clone->record_status)->toBe(RecordStatus::Draft)
        ->and($clone->approval_status)->toBe(StockWasteApprovalStatus::Pending)
        ->and($clone->posted_at)->toBeNull()
        ->and($clone->stockMutation)->toBeNull()
        ->and($variant->fresh()->stock_available)->toBe('10.000')
        ->and(StockMutation::query()->where('reference_id', $clone->id)->count())->toBe(0);
});

test('cancelling a waste restores stock and keeps both stock mutations', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $record = StockWaste::factory()->create(['quantity' => '3.000']);
    $variant = $record->productVariant;
    $variant->forceFill(['stock_available' => '10.000'])->saveQuietly();
    WarehouseStock::query()->create([
        'warehouse_id' => $record->warehouse_id,
        'product_variant_id' => $variant->id,
        'stock_available' => '10.000',
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);

    $record->post();
    Livewire::test(ViewStockWaste::class, ['record' => $record->id])
        ->assertActionVisible('cancel')
        ->assertActionDoesNotExist('post')
        ->assertActionDoesNotExist('approve')
        ->callAction('cancel')
        ->assertHasNoActionErrors();
    $record->cancel();

    expect($record->fresh()->waste_status)->toBe(StockWasteStatus::Cancelled)
        ->and($variant->fresh()->stock_available)->toBe('10.000')
        ->and($variant->fresh()->stock_out)->toBe('0.000')
        ->and(WarehouseStock::query()->where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $variant->id)->value('stock_available'))->toBe('10.000')
        ->and(WarehouseStock::query()->where('warehouse_id', $record->warehouse_id)->where('product_variant_id', $variant->id)->value('stock_out'))->toBe('0.000')
        ->and(StockMutation::query()->where('reference_id', $record->id)->count())->toBe(2)
        ->and(StockMutation::query()->where('reference_id', $record->id)->where('source', StockMutationSource::CancelStockWaste)->count())->toBe(1);
});
