<?php

use App\Enums\ApprovalModule;
use App\Enums\RecordStatus;
use App\Enums\StockAdjustmentApprovalStatus;
use App\Enums\StockAdjustmentReason;
use App\Enums\StockAdjustmentSource;
use App\Enums\StockAdjustmentType;
use App\Enums\StockMutationSource;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockAdjustments\Support\StockAdjustmentDetailPresenter;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockMutations\Pages\ListStockMutations;
use App\Filament\Resources\StockMutations\Pages\ViewStockMutation;
use App\Filament\Resources\StockMutations\StockMutationResource;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Resources\Stocks\Pages\ListLowStocks;
use App\Filament\Resources\Stocks\Pages\ListStocks;
use App\Filament\Resources\Stocks\Pages\StockWarehouses;
use App\Filament\Resources\Stocks\StockResource;
use App\Filament\Widgets\LowStockWidget;
use App\Filament\Widgets\StockOverview;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\ApprovalWorkflow;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor = User::factory()->sso()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($actor);
});

test('inventory navigation follows the requested stock menu order', function (): void {
    expect(StockResource::shouldRegisterNavigation())->toBeTrue()
        ->and(StockResource::getNavigationSort())->toBe(0)
        ->and(StockMovementResource::getNavigationSort())->toBe(2)
        ->and(StockOpnameResource::getNavigationSort())->toBe(3)
        ->and(StockAdjustmentResource::getNavigationSort())->toBe(4)
        ->and(StockMutationResource::getNavigationSort())->toBe(99);
});

test('stocks open directly on the product variant table', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $variant = ProductVariant::factory()->create();
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $variant->id,
    ]);

    $this->get(StockResource::getUrl('index'))->assertSuccessful();

    Livewire::test(ListStocks::class)
        ->assertCanSeeTableRecords([$stock])
        ->assertTableColumnExists('warehouse.name')
        ->assertTableColumnExists('productVariant.product.name')
        ->assertTableColumnExists('productVariant.name')
        ->assertTableColumnExists('productVariant.unit.name')
        ->assertTableColumnExists('stock_available')
        ->assertTableColumnExists('stock_reserved')
        ->assertTableColumnExists('stock_in_transit')
        ->assertTableColumnExists('stock_out')
        ->assertTableColumnExists('productVariant.low_stock_threshold')
        ->assertTableColumnExists('stockStatus')
        ->assertTableColumnHidden('warehouse.code')
        ->assertTableColumnHidden('productVariant.initial_stock');
});

test('warehouse detail reflects reservation, partial shipment, and receipt in every stock column', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $sccr = Warehouse::query()->where('code', 'WH-SCCR')->firstOrFail();
    $variant = ProductVariant::factory()->create([
        'stock_available' => 10,
        'low_stock_threshold' => 6,
    ]);
    $source = WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $farming->id,
        'to_warehouse_id' => $sccr->id,
        'approval_status' => 'approved',
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 4,
    ]);

    $assertStock = static function (Warehouse $warehouse, WarehouseStock $stock, int $available, int $reserved, int $inTransit, int $out, string $status): void {
        Livewire::test(ListStocks::class, ['warehouse' => $warehouse->id])
            ->assertTableColumnStateSet('stock_available', $available, $stock)
            ->assertTableColumnStateSet('stock_reserved', $reserved, $stock)
            ->assertTableColumnStateSet('stock_in_transit', $inTransit, $stock)
            ->assertTableColumnStateSet('stock_out', $out, $stock)
            ->assertTableColumnStateSet('stockStatus', $status, $stock);
    };

    $assertStock($farming, $source, 10, 0, 0, 0, 'Available');

    $movement->reserveRequestedStock();
    $assertStock($farming, $source, 6, 4, 0, 0, 'Low Stock');

    $movement->ship([['item_id' => $item->id, 'quantity_shipped' => 3, 'notes' => 'One unit was not sent.']]);
    $assertStock($farming, $source, 7, 0, 3, 0, 'Available');

    $movement->refresh()->receive([['item_id' => $item->id, 'quantity_received' => 2, 'notes' => 'One unit was damaged in transit.']]);
    $destination = WarehouseStock::query()
        ->where('warehouse_id', $sccr->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();

    $assertStock($farming, $source, 7, 0, 0, 3, 'Available');
    $assertStock($sccr, $destination, 2, 0, 0, 0, 'Low Stock');
    expect($variant->refresh()->stock_in_transit)->toBe('0.000');
});

test('warehouse detail reflects adjustment, opname, and order stock changes', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $variant = ProductVariant::factory()->create(['stock_available' => 8, 'low_stock_threshold' => 3]);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 8,
    ]);
    $assertStock = static function (int $available, int $reserved, int $out, string $status) use ($farming, $stock): void {
        Livewire::test(ListStocks::class, ['warehouse' => $farming->id])
            ->assertTableColumnStateSet('stock_available', $available, $stock)
            ->assertTableColumnStateSet('stock_reserved', $reserved, $stock)
            ->assertTableColumnStateSet('stock_in_transit', 0, $stock)
            ->assertTableColumnStateSet('stock_out', $out, $stock)
            ->assertTableColumnStateSet('stockStatus', $status, $stock);
    };

    StockMutation::record($variant, StockMutationSource::ManualAdjustment, -3, warehouse: $farming);
    $assertStock(5, 0, 0, 'Available');

    StockOpname::record($farming, $variant, 3);
    $assertStock(3, 0, 0, 'Low Stock');

    $orderItemId = (string) str()->uuid();
    StockMutation::reserveForOrder($variant, 3, $orderItemId, auth()->id(), $farming);
    $assertStock(0, 3, 0, 'Out of Stock');

    StockMutation::releaseFromOrder($variant, 1, $orderItemId, auth()->id(), $farming);
    $assertStock(1, 2, 0, 'Low Stock');

    StockMutation::fulfillForOrder($variant, 2, $orderItemId, auth()->id(), $farming);
    $assertStock(1, 0, 2, 'Low Stock');
});

test('new stock adjustments appear first even when their adjustment date is backdated', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);

    $older = StockAdjustment::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'created_at' => now(),
    ]);

    Livewire::test(CreateStockAdjustment::class)
        ->fillForm([
            'source' => StockAdjustmentSource::DirectAdjustment->value,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => 'tambah',
            'quantity' => 2,
            'created_at' => now()->subMonth()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $newer = StockAdjustment::query()->whereKeyNot($older->id)->sole();
    $older->update(['note' => 'Updated after the new adjustment was created.']);

    $page = Livewire::test(ListStockAdjustments::class)
        ->assertCanSeeTableRecords([$newer, $older]);

    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($page->instance()->getTable()->getRecordActionsColumnLabel())->toBe('Action')
        ->and(array_search('approval_status', array_keys($page->instance()->getTable()->getColumns()), true))
        ->toBeLessThan(array_search('adjustment_number', array_keys($page->instance()->getTable()->getColumns()), true));
});

test('stock adjustments rise to the top when they are updated', function (): void {
    $older = StockAdjustment::factory()->create([
        'type' => StockAdjustmentType::Tambah,
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinute(),
    ]);
    $newer = StockAdjustment::factory()->create([
        'type' => StockAdjustmentType::Tambah,
        'created_at' => now()->subMinute(),
        'updated_at' => now(),
    ]);

    Livewire::test(ListStockAdjustments::class)
        ->assertCanSeeTableRecords([$newer, $older]);
});

test('stock mutation table excludes historical fulfillment markers while preserving order mutations', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $historical = StockMutation::reserveForOrder($variant, 1, (string) str()->uuid(), auth()->id());
    $historical->update(['source' => StockMutationSource::OrderFulfilled, 'quantity_change' => 0]);
    $orderMutation = StockMutation::reserveForOrder($variant, 1, (string) str()->uuid(), auth()->id());

    Livewire::test(ListStockMutations::class)
        ->assertCanSeeTableRecords([$orderMutation])
        ->assertCanNotSeeTableRecords([$historical]);

    expect(StockMutationResource::getEloquentQuery()->whereKey($historical->id)->exists())->toBeFalse()
        ->and($historical->fresh())->not->toBeNull();
});

test('stock mutation rows open their source activity without selection controls', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $orderItem = OrderItem::factory()->create(['product_variant_id' => $variant->id]);
    $orderMutation = StockMutation::reserveForOrder($variant, 1, $orderItem->id, auth()->id());
    $adjustment = StockAdjustment::factory()->create(['product_variant_id' => $variant->id]);
    $adjustmentMutation = StockMutation::record($variant, StockMutationSource::ManualAdjustment, 1, StockAdjustment::class, $adjustment->id);
    $movement = StockMovement::factory()->create();
    $movementMutation = StockMutation::record($variant, StockMutationSource::Transfer, 1, StockMovement::class, $movement->id);
    $initialMutation = StockMutation::query()->where('product_variant_id', $variant->id)->where('source', StockMutationSource::InitialStock)->firstOrFail();
    $legacyOpname = StockOpname::record(Warehouse::factory()->create(), $variant, 9);
    $legacyMutation = StockMutation::query()->where('reference_id', $legacyOpname->id)->firstOrFail();

    $table = Livewire::test(ListStockMutations::class)->instance()->getTable();

    expect($table->getRecordUrl($orderMutation))->toBe(OrderResource::getUrl('view', ['record' => $orderItem->order]))
        ->and($table->getRecordUrl($adjustmentMutation))->toBe(StockAdjustmentResource::getUrl('view', ['record' => $adjustment]))
        ->and($table->getRecordUrl($movementMutation))->toBe(StockMovementResource::getUrl('view', ['record' => $movement]))
        ->and($table->getRecordUrl($initialMutation))->toBe(ProductResource::getUrl('view', ['record' => $variant->product]))
        ->and($table->getRecordUrl($legacyMutation))->toBe(StockMutationResource::getUrl('view', ['record' => $legacyMutation]))
        ->and($table->isSelectionEnabled())->toBeFalse()
        ->and($table->getToolbarActions())->toHaveCount(1);

    Livewire::test(ViewStockMutation::class, ['record' => $legacyMutation->id])->assertSuccessful();
    Livewire::test(ViewOrder::class, ['record' => $orderItem->order_id])->assertSuccessful();
});

test('stock mutation with a missing source opens its own details', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $mutation = StockMutation::reserveForOrder($variant, 1, (string) str()->uuid(), auth()->id());

    expect(StockMutationResource::activityUrl($mutation))->toBe(StockMutationResource::getUrl('view', ['record' => $mutation]));
});

test('adjustment create and clone preserve initial stock and immutable view snapshots', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['initial_stock' => 10, 'stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    Livewire::test(CreateStockAdjustment::class)
        ->fillForm([
            'source' => StockAdjustmentSource::DirectAdjustment->value,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => 'tambah',
            'quantity' => 5,
            'created_at' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $page = Livewire::test(ListStockAdjustments::class);

    $adjustment = StockAdjustment::query()->sole();
    expect($variant->refresh()->stock_available)->toBe('10.000')
        ->and($adjustment->source)->toBe(StockAdjustmentSource::DirectAdjustment)
        ->and($adjustment->adjustment_number)->toStartWith('ADJ-')
        ->and($adjustment->approval_status)->toBe(StockAdjustmentApprovalStatus::Approved)
        ->and($adjustment->stockMutations()->exists())->toBeFalse();

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertActionVisible('post')
        ->callAction('post')
        ->assertHasNoActionErrors();

    expect($adjustment->refresh()->approval_status)->toBe(StockAdjustmentApprovalStatus::Posted)
        ->and($variant->refresh()->stock_available)->toBe('15.000')
        ->and($variant->initial_stock)->toBe('10.000')
        ->and($adjustment->stockMutations->first()->stock_before)->toBe('10.000')
        ->and($adjustment->stockMutations->first()->stock_after)->toBe('15.000');

    StockMutation::reserveForOrder($variant, 3, (string) str()->uuid(), auth()->id());
    $items = app(StockAdjustmentDetailPresenter::class)->items($adjustment->fresh());
    expect($items[0]['system_qty'])->toBe('10')
        ->and($items[0]['adjusted_qty'])->toBe('15');
    $page->callTableAction('clone', $adjustment, data: [
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 2,
        'created_at' => now()->toDateTimeString(),
    ])
        ->assertHasNoActionErrors();

    $clone = StockAdjustment::query()->whereKeyNot($adjustment->id)->sole();
    expect($variant->refresh()->stock_available)->toBe('12.000')
        ->and($variant->initial_stock)->toBe('10.000')
        ->and($clone->adjustment_number)->not->toBe($adjustment->adjustment_number)
        ->and(StockAdjustment::query()->count())->toBe(2)
        ->and($clone->stockMutations()->exists())->toBeFalse();

    Livewire::test(ViewStockAdjustment::class, ['record' => $clone->getRouteKey()])
        ->callAction('post')
        ->assertHasNoActionErrors();

    expect($variant->refresh()->stock_available)->toBe('14.000')
        ->and($clone->refresh()->approval_status)->toBe(StockAdjustmentApprovalStatus::Posted);
});

test('stock adjustment detail presents progress information items and audit history', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $adjustment = StockAdjustment::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 5,
        'reason' => StockAdjustmentReason::Rusak,
        'note' => 'Adjustment detail note',
    ]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertSee('View Stock Adjustment')
        ->assertSee('Progress')
        ->assertSee('Adjustment Information')
        ->assertSee('Source')
        ->assertSee('Adjustment Date')
        ->assertSee('Items')
        ->assertSee('System Qty')
        ->assertSee('Adjusted Qty')
        ->assertSee('Variance')
        ->assertSee('Reason & Notes')
        ->assertSee('Adjustment detail note')
        ->assertSee('Detail Log')
        ->assertSee($variant->product->name)
        ->assertSee('Direct Adjustment')
        ->assertSee('10')
        ->assertSee('15');
});

test('stock adjustment product options exclude inactive and deleted inventory', function (): void {
    $warehouse = Warehouse::factory()->create();
    $visible = ProductVariant::factory()->create();
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $visible->id,
        'stock_available' => 5,
    ]);
    $otherWarehouseVariant = ProductVariant::factory()->create();
    WarehouseStock::factory()->create([
        'warehouse_id' => Warehouse::factory()->create()->id,
        'product_variant_id' => $otherWarehouseVariant->id,
        'stock_available' => 5,
    ]);
    $inactiveVariant = ProductVariant::factory()->create(['is_active' => false]);
    $inactiveProduct = ProductVariant::factory()->create();
    $inactiveProduct->product->update(['is_active' => false]);
    $deleted = ProductVariant::factory()->create();
    $deleted->delete();

    $page = Livewire::test(CreateStockAdjustment::class)
        ->fillForm(['warehouse_id' => $warehouse->id]);
    $schema = $page->instance()->getSchema('form');
    $options = $schema->getComponent('product_variant_id')->getOptions();

    expect($options)
        ->toHaveKey($visible->id)
        ->not->toHaveKey($otherWarehouseVariant->id)
        ->not->toHaveKey($inactiveVariant->id)
        ->not->toHaveKey($inactiveProduct->id)
        ->not->toHaveKey($deleted->id);
});

test('stock adjustment posts only after both approval levels', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockAdjustment->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);

    $role = Role::query()->where('name', 'super_admin')->firstOrFail();
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
        'level' => 1,
    ]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
        'level' => 2,
    ]);

    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $adjustment = StockAdjustment::query()->create([
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 5,
    ]);

    expect($adjustment->approval_status->value)->toBe('requested')
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and(StockMutation::query()->where('reference_id', $adjustment->id)->count())->toBe(0);

    $presenter = app(StockAdjustmentDetailPresenter::class);
    expect(collect($presenter->timeline($adjustment))->pluck('label')->all())
        ->toBe(['Requested', 'Level 1', 'Approved', 'Posted']);
    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionHidden('post')
        ->assertActionVisible('rejectApproval')
        ->assertActionVisible('reviseApproval');
    expect(fn () => $adjustment->applyToStock(auth()->id()))->toThrow(LogicException::class);
    Livewire::test(ListStockAdjustments::class)
        ->assertTableActionExists('view', record: $adjustment)
        ->assertTableActionExists('clone', record: $adjustment)
        ->assertTableActionDoesNotExist('approve', record: $adjustment)
        ->assertTableActionDoesNotExist('rejectApproval', record: $adjustment)
        ->assertTableActionDoesNotExist('reviseApproval', record: $adjustment)
        ->assertTableActionHidden('edit', $adjustment);

    app(ApprovalWorkflow::class)->approve($adjustment, auth()->user());

    expect($adjustment->refresh()->approval_status->value)->toBe('pending')
        ->and($adjustment->approval_level)->toBe(2)
        ->and($adjustment->approvalStatusLabel())->toBe('L1 Approved')
        ->and($variant->refresh()->stock_available)->toBe('10.000');
    Livewire::test(ListStockAdjustments::class)
        ->assertTableColumnStateSet('approval_status', 'L1 Approved', $adjustment);
    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertSee('L1 Approved')
        ->assertActionVisible('approve')
        ->assertActionHidden('post');
    expect(collect($presenter->timeline($adjustment))->pluck('state')->all())
        ->toBe(['complete', 'complete', 'current', 'pending']);

    app(ApprovalWorkflow::class)->approve($adjustment->refresh(), auth()->user());

    expect($adjustment->refresh()->approval_status->value)->toBe('approved')
        ->and($adjustment->approvalStatusLabel())->toBe('Approved')
        ->and($adjustment->applied_at)->toBeNull()
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and(StockMutation::query()->where('reference_id', $adjustment->id)->count())->toBe(0);
    expect(collect($presenter->timeline($adjustment))->pluck('state')->all())
        ->toBe(['complete', 'complete', 'complete', 'current']);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionVisible('post')
        ->callAction('post')
        ->assertHasNoActionErrors()
        ->assertDispatched('refresh-page')
        ->assertActionHidden('post');

    expect($adjustment->refresh()->approval_status)->toBe(StockAdjustmentApprovalStatus::Posted)
        ->and($adjustment->approvalStatusLabel())->toBe('Posted')
        ->and($adjustment->applied_at)->not->toBeNull()
        ->and($variant->refresh()->stock_available)->toBe('15.000')
        ->and(StockMutation::query()->where('reference_id', $adjustment->id)->count())->toBe(1);
    Livewire::test(ListStockAdjustments::class)
        ->assertTableColumnStateSet('approval_status', 'Posted', $adjustment);
    expect(collect($presenter->timeline($adjustment))->pluck('state')->all())
        ->toBe(['complete', 'complete', 'complete', 'complete']);

    $adjustment->applyToStock(auth()->id());
    expect(StockMutation::query()->where('reference_id', $adjustment->id)->count())->toBe(1);
});

test('stock adjustment process actions refresh the open detail page', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockAdjustment->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);

    $role = Role::query()->where('name', 'super_admin')->firstOrFail();
    foreach ([1, 2] as $level) {
        ApprovalSettingLevel::factory()->create([
            'approval_setting_id' => $setting->id,
            'role_id' => $role->id,
            'level' => $level,
        ]);
    }

    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $adjustment = StockAdjustment::factory()->create([
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 2,
    ]);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->callAction('approve')
        ->assertSee('L1 Approved')
        ->assertActionVisible('approve')
        ->assertActionHidden('post')
        ->callAction('approve')
        ->assertSee('Approved')
        ->assertActionHidden('approve')
        ->assertActionVisible('post')
        ->callAction('post')
        ->assertSee('Posted')
        ->assertActionHidden('post');

    expect($variant->refresh()->stock_available)->toBe('12.000');
});

test('stock adjustment detail reloads its progress when a process refresh is dispatched', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockAdjustment->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);

    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
        'level' => 1,
    ]);

    $adjustment = StockAdjustment::factory()->create([
        'type' => 'tambah',
        'quantity' => 2,
    ]);
    $page = Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertSee('Requested');

    app(ApprovalWorkflow::class)->approve($adjustment, auth()->user());

    $page->dispatch('refresh-page')
        ->assertSee('Approved')
        ->assertActionVisible('post')
        ->assertActionHidden('approve');
});

test('revision and rejection update the open stock adjustment detail', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockAdjustment->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);

    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
        'level' => 1,
    ]);

    $revised = StockAdjustment::factory()->create(['type' => 'tambah']);
    Livewire::test(ViewStockAdjustment::class, ['record' => $revised->getRouteKey()])
        ->callAction('reviseApproval', data: ['notes' => 'Check the quantity.'])
        ->assertHasNoActionErrors()
        ->assertSee('Revision requested')
        ->assertActionHidden('approve')
        ->assertActionHidden('reviseApproval');

    $rejected = StockAdjustment::factory()->create(['type' => 'tambah']);
    Livewire::test(ViewStockAdjustment::class, ['record' => $rejected->getRouteKey()])
        ->callAction('rejectApproval', data: ['notes' => 'Invalid adjustment.'])
        ->assertHasNoActionErrors()
        ->assertSee('Rejected')
        ->assertActionHidden('approve')
        ->assertActionHidden('rejectApproval');
});

test('revised stock adjustments stay saved and can be resubmitted without list approval actions', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockAdjustment->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);
    $role = Role::query()->where('name', 'super_admin')->firstOrFail();
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
        'level' => 1,
    ]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => Role::query()->create([
            'name' => 'Inventory Reviewer',
            'code' => 'inventory-reviewer',
            'guard_name' => 'web',
        ])->id,
        'level' => 2,
    ]);

    $adjustment = StockAdjustment::factory()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'type' => 'tambah',
        'quantity' => 2,
    ]);
    app(ApprovalWorkflow::class)->approve($adjustment, auth()->user());
    expect(collect(app(StockAdjustmentDetailPresenter::class)->timeline($adjustment->refresh()))->pluck('state')->all())
        ->toBe(['complete', 'complete', 'current', 'pending']);
    app(ApprovalWorkflow::class)->revise($adjustment, auth()->user(), 'Update the quantity.');

    expect($adjustment->refresh()->approval_status)->toBe(StockAdjustmentApprovalStatus::Revised)
        ->and($adjustment->record_status)->toBe(RecordStatus::Saved)
        ->and(StockAdjustmentResource::canEdit($adjustment))->toBeTrue();
    expect(collect(app(StockAdjustmentDetailPresenter::class)->timeline($adjustment))->pluck('state')->all())
        ->toBe(['complete', 'stopped', 'pending', 'pending']);

    Livewire::test(ListStockAdjustments::class)
        ->assertTableActionVisible('edit', $adjustment)
        ->assertTableActionDoesNotExist('approve', record: $adjustment)
        ->assertTableActionDoesNotExist('rejectApproval', record: $adjustment)
        ->assertTableActionDoesNotExist('reviseApproval', record: $adjustment);

    Livewire::test(ListStockAdjustments::class)
        ->callTableAction('edit', $adjustment, data: [
            'warehouse_id' => $adjustment->warehouse_id,
            'product_variant_id' => $adjustment->product_variant_id,
            'type' => 'tambah',
            'quantity' => 4,
            'created_at' => $adjustment->created_at->toDateTimeString(),
        ])
        ->assertHasNoActionErrors();

    expect($adjustment->refresh()->record_status)->toBe(RecordStatus::Saved)
        ->and($adjustment->approval_status)->toBe(StockAdjustmentApprovalStatus::Requested)
        ->and($adjustment->approval_level)->toBe(1)
        ->and($adjustment->quantity)->toBe('4.000')
        ->and(app(ApprovalWorkflow::class)->canApprove($adjustment, auth()->user()))->toBeTrue()
        ->and($adjustment->stockMutations()->exists())->toBeFalse();
    Livewire::test(ListStockAdjustments::class)->assertTableActionHidden('edit', $adjustment);
});

test('draft stock adjustment can be saved and submitted without changing stock early', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $data = [
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 5,
        'created_at' => now()->toDateTimeString(),
    ];

    Livewire::test(CreateStockAdjustment::class)
        ->fillForm([...$data, 'source' => StockAdjustmentSource::DirectAdjustment->value])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    $draft = StockAdjustment::query()->sole();
    expect($draft->record_status)->toBe(RecordStatus::Draft)
        ->and($draft->approval_status)->toBe(StockAdjustmentApprovalStatus::Pending)
        ->and($draft->approval_setting_id)->toBeNull()
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and($draft->stockMutations()->exists())->toBeFalse();
    Livewire::test(ListStockAdjustments::class)
        ->assertTableColumnStateSet('approval_status', 'Pending', $draft);
    $detail = Livewire::test(ViewStockAdjustment::class, ['record' => $draft->getRouteKey()]);
    expect($detail->instance()->getSchema('infolist')->getComponent('approval_status')->getState())->toBe('Pending');

    Livewire::test(ListStockAdjustments::class)
        ->assertTableActionVisible('edit', $draft)
        ->callTableAction('edit', $draft, data: [...$data, 'quantity' => 3], arguments: ['draft' => true])
        ->assertHasNoActionErrors();

    expect($draft->refresh()->record_status)->toBe(RecordStatus::Draft)
        ->and($draft->approval_status)->toBe(StockAdjustmentApprovalStatus::Pending)
        ->and($draft->quantity)->toBe('3.000')
        ->and($variant->refresh()->stock_available)->toBe('10.000');

    Livewire::test(ListStockAdjustments::class)
        ->callTableAction('edit', $draft, data: [...$data, 'quantity' => 3])
        ->assertHasNoActionErrors();

    expect($draft->refresh()->record_status)->toBe(RecordStatus::Saved)
        ->and($draft->approval_status)->toBe(StockAdjustmentApprovalStatus::Approved)
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and($draft->stockMutations()->count())->toBe(0);
    Livewire::test(ListStockAdjustments::class)->assertTableActionHidden('edit', $draft);

    Livewire::test(ViewStockAdjustment::class, ['record' => $draft->getRouteKey()])
        ->callAction('post')
        ->assertHasNoActionErrors();

    expect($draft->refresh()->approval_status)->toBe(StockAdjustmentApprovalStatus::Posted)
        ->and($variant->refresh()->stock_available)->toBe('13.000')
        ->and($draft->stockMutations()->count())->toBe(1);

    Livewire::test(ListStockAdjustments::class)
        ->callTableAction('clone', $draft, data: [...$data, 'quantity' => 2], arguments: ['draft' => true])
        ->assertHasNoActionErrors();

    $clone = StockAdjustment::query()->whereKeyNot($draft->id)->sole();
    expect($clone->record_status)->toBe(RecordStatus::Draft)
        ->and($clone->approval_status)->toBe(StockAdjustmentApprovalStatus::Pending)
        ->and($clone->approval_setting_id)->toBeNull()
        ->and($clone->stockMutations()->exists())->toBeFalse()
        ->and($variant->refresh()->stock_available)->toBe('13.000');
});

test('only draft stock adjustments can be deleted and restored with lifecycle audit', function (): void {
    $draft = StockAdjustment::factory()->create(['record_status' => RecordStatus::Draft]);
    $saved = StockAdjustment::factory()->create(['record_status' => RecordStatus::Saved]);

    Livewire::test(ListStockAdjustments::class)
        ->assertTableActionVisible('delete', $draft)
        ->assertTableActionHidden('delete', $saved)
        ->mountTableAction('delete', $draft)
        ->unmountTableAction();

    expect($draft->refresh()->trashed())->toBeFalse();

    Livewire::test(ListStockAdjustments::class)
        ->callTableAction('delete', $draft)
        ->assertHasNoActionErrors();

    $deleted = StockAdjustment::withTrashed()->findOrFail($draft->id);

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->record_status)->toBe(RecordStatus::Deleted)
        ->and($deleted->previous_record_status)->toBe(RecordStatus::Draft)
        ->and($deleted->deleted_by)->toBe(auth()->id())
        ->and($deleted->deleted_at)->not->toBeNull();

    Livewire::test(ListStockAdjustments::class)
        ->assertCanNotSeeTableRecords([$deleted])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$deleted])
        ->assertTableActionVisible('restore', $deleted)
        ->callTableAction('restore', $deleted)
        ->assertHasNoActionErrors();

    expect($deleted->refresh()->trashed())->toBeFalse()
        ->and($deleted->record_status)->toBe(RecordStatus::Draft)
        ->and($deleted->previous_record_status)->toBeNull()
        ->and($deleted->deleted_by)->toBeNull();
});

test('deleted stock adjustment details can still be opened', function (): void {
    $draft = StockAdjustment::factory()->create(['record_status' => RecordStatus::Draft]);
    $draft->delete();

    $this->get(StockAdjustmentResource::getUrl('view', ['record' => $draft]))
        ->assertSuccessful();
});

test('invalid reduction creates neither adjustment nor mutation', function (int|float $quantity): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 3]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 3,
    ]);
    $mutations = StockMutation::query()->count();

    Livewire::test(CreateStockAdjustment::class)
        ->fillForm([
            'source' => StockAdjustmentSource::DirectAdjustment->value,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => 'kurang',
            'quantity' => $quantity,
            'created_at' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['quantity']);

    expect(StockAdjustment::query()->count())->toBe(0)
        ->and(StockMutation::query()->count())->toBe($mutations)
        ->and($variant->refresh()->stock_available)->toBe('3.000');
})->with([5, 0, -1, 1.0001]);

test('clone validates reductions and view does not invent a missing snapshot', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 3]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 3,
    ]);
    $adjustment = StockAdjustment::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 1,
    ]);
    $adjustment->applyToStock(auth()->id());
    $count = StockMutation::query()->count();
    $page = Livewire::test(ListStockAdjustments::class)
        ->callTableAction('clone', $adjustment, data: [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'type' => 'kurang',
            'quantity' => 5,
            'created_at' => now()->toDateTimeString(),
        ])
        ->assertHasActionErrors(['quantity']);
    expect(StockAdjustment::query()->count())->toBe(1)
        ->and(StockMutation::query()->count())->toBe($count)
        ->and($variant->refresh()->stock_available)->toBe('4.000');

    $adjustment->stockMutations()->delete();
    $item = app(StockAdjustmentDetailPresenter::class)->items($adjustment->fresh())[0];
    expect($item['system_qty'])->toBe('-')
        ->and($item['adjusted_qty'])->toBe('-')
        ->and($item['unit'])->toBe($variant->unit->code ?? $variant->unit->name);
});

test('manual mutation uses fresh stock and rejects a negative result', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    ProductVariant::query()->whereKey($variant)->update(['stock_available' => 3]);

    expect(fn () => StockMutation::record($variant, StockMutationSource::ManualAdjustment, -5))
        ->toThrow(ValidationException::class);
    expect($variant->refresh()->stock_available)->toBe('3.000');

    $mutation = StockMutation::record($variant, StockMutationSource::ManualAdjustment, 2);
    expect($mutation->stock_before)->toBe('3.000')
        ->and($mutation->stock_after)->toBe('5.000');
});

test('stock warehouse and detail exports use only their visible records and columns', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $stock = WarehouseStock::factory()->create(['warehouse_id' => $farming->id]);
    $exports = [];
    $response = response()->streamDownload(static fn () => print 'ok', 'stocks.csv');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadCsv')
        ->twice()
        ->withArgs(function (Collection $records, string $model, TableExportDefinition $definition) use (&$exports): bool {
            $exports[] = [
                'model' => $model,
                'records' => $records->pluck('id')->all(),
                'columns' => array_keys($definition->columns),
            ];

            return true;
        })
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    Livewire::test(StockWarehouses::class)
        ->callTableAction('exportCsv')
        ->assertHasNoActionErrors();

    Livewire::test(ListStocks::class, ['warehouse' => $farming->id])
        ->callTableAction('exportCsv')
        ->assertHasNoActionErrors();

    expect($exports[0]['model'])->toBe(Warehouse::class)
        ->and($exports[0]['columns'])->toBe(['name', 'code', 'total_items_count', 'critical_items_count'])
        ->and(count($exports[0]['records']))->toBe(2)
        ->and($exports[1]['model'])->toBe(WarehouseStock::class)
        ->and($exports[1]['records'])->toBe([$stock->id])
        ->and($exports[1]['columns'])->toContain('productVariant.name')
        ->not->toContain('warehouse.name');
});

test('stock adjustment records the selected warehouse stock', function (): void {
    $warehouse = Warehouse::factory()->create(['code' => 'WH-ADJUSTMENT']);
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);

    $adjustment = StockAdjustment::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 4,
        'reason' => 'lainnya',
    ]);
    $adjustment->applyToStock(auth()->id());

    expect(WarehouseStock::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_variant_id', $variant->id)
        ->value('stock_available'))->toBe('4.000')
        ->and($variant->refresh()->stock_available)->toBe('14.000')
        ->and(StockMutation::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->exists())->toBeTrue();
});

test('order reservation uses the selected fulfillment warehouse', function (): void {
    $warehouse = Warehouse::factory()->create(['code' => 'WH-FULFILLMENT']);
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);

    $mutation = StockMutation::reserveForOrder(
        $variant,
        3,
        (string) str()->uuid(),
        auth()->id(),
        $warehouse,
    );

    $stock = WarehouseStock::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();

    expect($mutation->warehouse_id)->toBe($warehouse->id)
        ->and($stock->stock_available)->toBe('7.000')
        ->and($stock->stock_reserved)->toBe('3.000')
        ->and($variant->refresh()->stock_available)->toBe('7.000')
        ->and($variant->stock_reserved)->toBe('3.000');
});

test('stocks exclude variants whose parent is inactive', function (): void {
    $visible = ProductVariant::factory()->create();
    $hidden = ProductVariant::factory()->create(['is_active' => true]);
    $hidden->product->update(['is_active' => false]);
    WarehouseStock::factory()->create(['product_variant_id' => $visible->id]);
    WarehouseStock::factory()->create(['product_variant_id' => $hidden->id]);

    expect(StockResource::getEloquentQuery()->where('product_variant_id', $visible->id)->exists())->toBeTrue()
        ->and(StockResource::getEloquentQuery()->where('product_variant_id', $hidden->id)->exists())->toBeFalse();
});

test('inventory searches match the whole variant phrase and product filters narrow mutations', function (): void {
    $gradeA = ProductVariant::factory()->create(['name' => 'Grade A', 'stock_available' => 10]);
    $gradeB = ProductVariant::factory()->create(['name' => 'Grade B', 'stock_available' => 10]);
    $adjustments = collect([$gradeA, $gradeB])->map(fn (ProductVariant $variant): StockAdjustment => StockAdjustment::query()->create([
        'product_variant_id' => $variant->id,
        'type' => 'tambah',
        'quantity' => 1,
    ]));

    Livewire::test(ListStockAdjustments::class)->searchTable('Grade A')
        ->assertCanSeeTableRecords([$adjustments[0]])
        ->assertCanNotSeeTableRecords([$adjustments[1]]);

    $aMutations = StockMutation::query()->where('product_variant_id', $gradeA->id)->get();
    $bMutations = StockMutation::query()->where('product_variant_id', $gradeB->id)->get();
    Livewire::test(ListStockMutations::class)->searchTable('Grade A')
        ->assertCanSeeTableRecords($aMutations)->assertCanNotSeeTableRecords($bMutations);
    Livewire::test(ListStockMutations::class)->filterTable('product_id', $gradeB->product_id)
        ->assertCanSeeTableRecords($bMutations)->assertCanNotSeeTableRecords($aMutations);
    Livewire::test(ListStockMutations::class)->filterTable('product_variant_id', $gradeA->id)
        ->assertCanSeeTableRecords($aMutations)->assertCanNotSeeTableRecords($bMutations);
});

test('minimum stock status is separate from out of stock', function (int $available, bool $alert, string $status): void {
    $variant = ProductVariant::factory()->make(['stock_available' => $available, 'low_stock_threshold' => 10]);
    expect($variant->is_low_stock)->toBe($alert)
        ->and($variant->stockStatus)->toBe($status);
})->with([[11, false, 'Available'], [10, true, 'Low Stock'], [1, true, 'Low Stock'], [0, false, 'Out of Stock']]);

test('low stock scope excludes out of stock and inactive inventory', function (): void {
    $lowStock = ProductVariant::factory()->create([
        'stock_available' => 2,
        'low_stock_threshold' => 5,
    ]);
    $outOfStock = ProductVariant::factory()->create([
        'stock_available' => 0,
        'low_stock_threshold' => 5,
    ]);
    $inactiveVariant = ProductVariant::factory()->create([
        'stock_available' => 2,
        'low_stock_threshold' => 5,
        'is_active' => false,
    ]);
    $inactiveProduct = ProductVariant::factory()->create([
        'stock_available' => 2,
        'low_stock_threshold' => 5,
    ]);
    $inactiveProduct->product->update(['is_active' => false]);

    $results = ProductVariant::query()->inventoryVisible()->lowStock()->get();

    expect($results->modelKeys())
        ->toContain($lowStock->getKey())
        ->not->toContain($outOfStock->getKey())
        ->not->toContain($inactiveVariant->getKey())
        ->not->toContain($inactiveProduct->getKey());
});

test('low stock page and dashboard widget show only low stock variants', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $lowStock = ProductVariant::factory()->create([
        'name' => 'Low Stock Variant',
        'stock_available' => 2,
        'low_stock_threshold' => 5,
    ]);
    $available = ProductVariant::factory()->create([
        'name' => 'Available Variant',
        'stock_available' => 20,
        'low_stock_threshold' => 5,
    ]);
    $lowStockRow = WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $lowStock->id,
        'stock_available' => 2,
    ]);
    $availableStockRow = WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $available->id,
        'stock_available' => 20,
    ]);

    Livewire::test(ListLowStocks::class)
        ->assertCanSeeTableRecords([$lowStockRow])
        ->assertCanNotSeeTableRecords([$availableStockRow]);

    Livewire::test(LowStockWidget::class)
        ->assertSee('Low Stock Variant')
        ->assertDontSee('Available Variant');
});

test('stocks overview lists two warehouses and details isolate their stock', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 15]);
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $sccr = Warehouse::query()->where('code', 'WH-SCCR')->firstOrFail();
    $other = Warehouse::factory()->create();

    WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $sccr->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 5,
    ]);
    $otherStock = WarehouseStock::factory()->create([
        'warehouse_id' => $other->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 7,
    ]);

    $farmingStock = WarehouseStock::query()
        ->where('warehouse_id', $farming->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();
    $sccrStock = WarehouseStock::query()
        ->where('warehouse_id', $sccr->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();

    $overview = Livewire::test(StockWarehouses::class)
        ->assertCanSeeTableRecords([$farming, $sccr])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableActionHasUrl('view', StockResource::getUrl('warehouse', ['warehouse' => $farming->id]), $farming)
        ->assertTableActionExists('exportCsv')
        ->assertTableBulkActionExists('exportSelectedCsv');

    expect($overview->instance()->getTableRecords()->pluck('code')->all())
        ->toBe(['WH-FARMING', 'WH-SCCR']);

    Livewire::test(ListStocks::class, ['warehouse' => $farming->id])
        ->assertCanSeeTableRecords([$farmingStock])
        ->assertCanNotSeeTableRecords([$sccrStock, $otherStock])
        ->assertTableColumnHidden('warehouse.name')
        ->assertTableColumnExists('productVariant.name')
        ->assertTableColumnExists('stock_available')
        ->assertTableColumnExists('stock_reserved')
        ->assertTableColumnExists('stock_in_transit')
        ->assertTableColumnExists('stock_out')
        ->assertTableColumnExists('productVariant.low_stock_threshold')
        ->assertTableColumnExists('stockStatus')
        ->assertTableActionExists('exportCsv')
        ->assertTableBulkActionExists('exportSelectedCsv');

    Livewire::test(ListStocks::class, ['warehouse' => $sccr->id])
        ->assertCanSeeTableRecords([$sccrStock])
        ->assertCanNotSeeTableRecords([$farmingStock, $otherStock]);

    $this->get(StockResource::getUrl('warehouse', ['warehouse' => $other->id]))->assertNotFound();

    expect(StockResource::shouldRegisterNavigation())->toBeTrue();
});

test('stock cards open matching lists and warehouse counts ignore invisible inventory', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $sccr = Warehouse::query()->where('code', 'WH-SCCR')->firstOrFail();
    $other = Warehouse::factory()->create();
    $outVariant = ProductVariant::factory()->create(['low_stock_threshold' => 5]);
    $lowVariant = ProductVariant::factory()->create(['low_stock_threshold' => 5]);
    $availableVariant = ProductVariant::factory()->create(['low_stock_threshold' => 5]);
    $hiddenVariant = ProductVariant::factory()->create(['is_active' => false]);

    $out = WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $outVariant->id, 'stock_available' => 0]);
    $low = WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $lowVariant->id, 'stock_available' => 3]);
    $available = WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $availableVariant->id, 'stock_available' => 10]);
    $sccrLow = WarehouseStock::factory()->create(['warehouse_id' => $sccr->id, 'product_variant_id' => $availableVariant->id, 'stock_available' => 2]);
    $hiddenStock = WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $hiddenVariant->id, 'stock_available' => 0]);
    $otherStock = WarehouseStock::factory()->create(['warehouse_id' => $other->id, 'product_variant_id' => $outVariant->id, 'stock_available' => 0]);

    Livewire::test(StockWarehouses::class)
        ->assertTableColumnStateSet('total_items_count', 3, $farming)
        ->assertTableColumnStateSet('critical_items_count', 2, $farming)
        ->assertTableColumnStateSet('total_items_count', 1, $sccr)
        ->assertTableColumnStateSet('critical_items_count', 1, $sccr);

    $stats = collect(Livewire::test(StockOverview::class)->instance()->getSchema('content')->getComponents()[0]->getChildComponents())
        ->keyBy(fn ($stat): string => $stat->getLabel());

    expect($stats['Total Variants']->getValue())->toBe(3)
        ->and($stats['Available']->getValue())->toBe(1)
        ->and($stats['Low Stock']->getValue())->toBe(2)
        ->and($stats['Out of Stock']->getValue())->toBe(1)
        ->and($stats['Total Variants']->getUrl())->toBe(StockResource::getUrl('status', ['status' => 'all']))
        ->and($stats['Available']->getUrl())->toBe(StockResource::getUrl('status', ['status' => 'available']))
        ->and($stats['Low Stock']->getUrl())->toBe(StockResource::getUrl('low-stock'))
        ->and($stats['Out of Stock']->getUrl())->toBe(StockResource::getUrl('status', ['status' => 'out-of-stock']))
        ->and($stats['Total Variants']->getExtraAttributeBag()->get('class'))->toContain('stock-overview-stat--info')
        ->and($stats['Available']->getExtraAttributeBag()->get('class'))->toContain('stock-overview-stat--success')
        ->and($stats['Low Stock']->getExtraAttributeBag()->get('class'))->toContain('stock-overview-stat--warning')
        ->and($stats['Out of Stock']->getExtraAttributeBag()->get('class'))->toContain('stock-overview-stat--danger');

    Livewire::test(ListStocks::class)
        ->assertSee('wire:name="App\\Filament\\Widgets\\StockOverview"', escape: false);

    Livewire::test(ListStocks::class, ['status' => 'all'])
        ->assertCanSeeTableRecords([$out, $low, $available, $sccrLow])
        ->assertCanNotSeeTableRecords([$hiddenStock, $otherStock]);

    Livewire::test(ListStocks::class, ['status' => 'available'])
        ->assertCanSeeTableRecords([$available])
        ->assertCanNotSeeTableRecords([$out, $low, $sccrLow, $otherStock]);

    Livewire::test(ListLowStocks::class)
        ->assertCanSeeTableRecords([$low, $sccrLow])
        ->assertCanNotSeeTableRecords([$out, $available, $otherStock]);

    Livewire::test(ListStocks::class, ['status' => 'out-of-stock'])
        ->assertCanSeeTableRecords([$out])
        ->assertCanNotSeeTableRecords([$low, $available, $sccrLow, $otherStock]);

    $this->get(StockResource::getUrl('status', ['status' => 'invalid']))->assertNotFound();
});

test('sorting stock status follows out of stock, low stock, then available', function (): void {
    $farming = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $outVariant = ProductVariant::factory()->create(['low_stock_threshold' => 5]);
    $lowVariant = ProductVariant::factory()->create(['low_stock_threshold' => 5]);
    $availableVariant = ProductVariant::factory()->create(['low_stock_threshold' => 5]);

    $out = WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $outVariant->id, 'stock_available' => 0]);
    $low = WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $lowVariant->id, 'stock_available' => 4]);
    $available = WarehouseStock::factory()->create(['warehouse_id' => $farming->id, 'product_variant_id' => $availableVariant->id, 'stock_available' => 6]);

    $page = Livewire::test(ListStocks::class, ['warehouse' => $farming->id]);
    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe([$out->id, $low->id, $available->id]);

    $page->sortTable('stockStatus', 'asc');
    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe([$out->id, $low->id, $available->id]);

    $page->sortTable('stockStatus', 'desc');
    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe([$available->id, $low->id, $out->id]);
});
