<?php

use App\Enums\ApprovalModule;
use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Filament\Resources\StockMovements\Pages\EditStockMovement;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\StockMovements\Support\StockMovementDetailPresenter;
use App\Filament\Resources\StockMovements\Tables\Actions\CloneStockMovementAction;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Quantity;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('stock movement table follows the qa column order', function (): void {
    $page = Livewire::test(ListStockMovements::class)
        ->assertTableColumnExists('row_number')
        ->assertTableColumnExists('approval_status')
        ->assertTableColumnExists('movement_number')
        ->assertTableColumnExists('movement_date')
        ->assertTableColumnExists('fromWarehouse.name')
        ->assertTableColumnExists('toWarehouse.name')
        ->assertTableColumnExists('requestedBy.name')
        ->assertTableColumnExists('shipped_at')
        ->assertTableColumnExists('received_at')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('notes')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('createdBy.name')
        ->assertTableColumnExists('updated_at')
        ->assertTableColumnExists('updatedBy.name')
        ->assertTableColumnExists('deleted_at')
        ->assertTableColumnExists('deletedBy.name')
        ->assertTableColumnDoesNotExist('progress')
        ->assertTableColumnDoesNotExist('logs_count');

    $page->assertTableActionDoesNotExist('submit');

    expect($page->instance()->getTable()->getRecordActionsColumnLabel())->toBe('Action');
});

test('stock movement status column exposes lifecycle labels', function (): void {
    $saved = StockMovement::factory()->create(['status' => StockMovementStatus::Requested]);
    $draft = StockMovement::factory()->create(['status' => StockMovementStatus::Draft]);
    $deleted = StockMovement::factory()->create(['status' => StockMovementStatus::Draft]);
    $deleted->delete();
    $deleted = StockMovement::withTrashed()->findOrFail($deleted->id);

    Livewire::test(ListStockMovements::class)
        ->assertTableColumnStateSet('status', 'Saved', $saved)
        ->assertTableColumnStateSet('status', 'Draft', $draft)
        ->assertTableColumnStateSet('approval_status', 'Pending', $draft)
        ->filterTable('lifecycle_status', 'deleted')
        ->assertCanSeeTableRecords([$deleted])
        ->assertTableColumnStateSet('status', 'Deleted', $deleted)
        ->assertTableActionVisible('restore', $deleted);
});

test('rejected stock movements show rejected in the approval column', function (): void {
    $movement = StockMovement::factory()->create([
        'status' => StockMovementStatus::Cancelled,
        'approval_status' => StockMovementApprovalStatus::Rejected,
    ]);

    Livewire::test(ListStockMovements::class)
        ->assertTableColumnStateSet('approval_status', 'Rejected', $movement);
});

test('restoring a deleted draft stock movement returns it to draft status', function (): void {
    $movement = StockMovement::factory()->create(['status' => StockMovementStatus::Draft]);
    $movement->delete();
    $deleted = StockMovement::withTrashed()->findOrFail($movement->id);

    Livewire::test(ListStockMovements::class)
        ->filterTable('lifecycle_status', 'deleted')
        ->assertTableActionVisible('restore', $deleted)
        ->assertTableActionHidden('edit', $deleted)
        ->assertTableActionHidden('delete', $deleted)
        ->callTableAction('restore', $deleted)
        ->assertHasNoActionErrors();

    expect($deleted->refresh()->trashed())->toBeFalse()
        ->and($deleted->status)->toBe(StockMovementStatus::Draft);
});

test('saving an edited draft submits it and reserves its stock', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 5]);
    $sourceStock = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 5,
        'stock_reserved' => 0,
    ]);
    $movement = StockMovement::factory()->create([
        'approval_setting_id' => $setting->id,
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
        'requested_by' => $this->actor->id,
        'status' => StockMovementStatus::Draft,
        'approval_status' => StockMovementApprovalStatus::Pending,
        'approval_level' => 0,
        'created_by' => $this->actor->id,
    ]);
    StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    Livewire::test(EditStockMovement::class, ['record' => $movement->getRouteKey()])
        ->fillForm(['notes' => 'Submit this draft'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Requested)
        ->and($movement->approval_status)->toBe(StockMovementApprovalStatus::Requested)
        ->and($movement->approval_level)->toBe(1)
        ->and($movement->reserved_at)->not->toBeNull()
        ->and($sourceStock->refresh()->stock_available)->toBe('3.000')
        ->and($sourceStock->stock_reserved)->toBe('2.000');
});

test('draft movements expose edit and delete while saved movements only expose view and clone', function (): void {
    $draft = StockMovement::factory()->create(['status' => StockMovementStatus::Draft]);
    $saved = StockMovement::factory()->create([
        'status' => StockMovementStatus::Requested,
        'approval_status' => StockMovementApprovalStatus::Requested,
    ]);

    Livewire::test(ListStockMovements::class)
        ->assertTableActionVisible('edit', $draft)
        ->assertTableActionVisible('delete', $draft)
        ->assertTableActionHidden('edit', $saved)
        ->assertTableActionHidden('delete', $saved)
        ->assertTableActionVisible('view', $saved)
        ->assertTableActionVisible('clone', $saved)
        ->assertTableActionHidden('ship', $saved)
        ->assertTableActionHidden('receive', $saved)
        ->assertTableActionHidden('rejectDispatch', $saved)
        ->assertTableActionDoesNotExist('cancel');
});

test('stock movement progress advances to the next approval level', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $movement = StockMovement::factory()->create([
        'approval_setting_id' => $setting->id,
        'approval_status' => StockMovementApprovalStatus::Pending,
        'approval_level' => 2,
    ]);
    $movement->approvalLogs()->create([
        'module_code' => ApprovalModule::StockMovement,
        'level' => 1,
        'action' => 'approved',
        'acted_by' => $this->actor->id,
    ]);

    $steps = app(StockMovementDetailPresenter::class)->timeline($movement->fresh());

    $stepsByKey = collect($steps)->keyBy('key');

    expect($steps)->toHaveCount(5)
        ->and($stepsByKey->keys()->all())->toBe([
            'requested',
            'level-1',
            'approved',
            'shipped',
            'received',
        ])
        ->and($stepsByKey['requested']['complete'])->toBeTrue()
        ->and($stepsByKey['level-1']['complete'])->toBeTrue()
        ->and($stepsByKey['approved']['complete'])->toBeFalse()
        ->and($stepsByKey['approved']['state'])->toBe('current')
        ->and($stepsByKey['shipped']['state'])->toBe('pending')
        ->and($stepsByKey['received']['state'])->toBe('pending');
});

test('stock movement table exposes a view action and displays records', function (): void {
    $movement = StockMovement::factory()->create();

    Livewire::test(ListStockMovements::class)
        ->assertCanSeeTableRecords([$movement])
        ->assertTableActionExists('view', record: $movement);
});

test('stock movement approval status shows in transit after shipping and stock buckets update correctly', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-SHIP-LABEL-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-SHIP-LABEL-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 12]);
    $sourceStock = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 12,
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
        'approval_status' => StockMovementApprovalStatus::Approved,
        'status' => StockMovementStatus::Requested,
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 4,
    ]);

    $movement->reserveRequestedStock();
    $movement->ship([
        [
            'item_id' => $item->id,
            'quantity_shipped' => 4,
            'notes' => 'Sent to branch.',
        ],
    ]);

    Livewire::test(ListStockMovements::class)
        ->assertTableColumnStateSet('approval_status', 'In Transit', $movement->fresh());

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Shipped)
        ->and($sourceStock->refresh()->stock_available)->toBe('8.000')
        ->and($sourceStock->stock_reserved)->toBe('0.000')
        ->and($sourceStock->stock_in_transit)->toBe('4.000')
        ->and($sourceStock->stock_out)->toBe('0.000');
});

test('newly created stock movements appear first in the table', function (): void {
    $older = StockMovement::factory()->create([
        'movement_number' => 'SM-20260101-000000000001',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
    $newer = StockMovement::factory()->create([
        'movement_number' => 'SM-20260101-000000000002',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ListStockMovements::class)
        ->assertCanSeeTableRecords([$newer, $older]);
});

test('stock movements rise to the top when they are updated after a stock change', function (): void {
    $older = StockMovement::factory()->create([
        'status' => StockMovementStatus::Requested,
        'updated_at' => now()->subMinute(),
    ]);
    $newer = StockMovement::factory()->create([
        'status' => StockMovementStatus::Requested,
        'updated_at' => now(),
    ]);

    $newer->update(['status' => StockMovementStatus::Shipped, 'updated_at' => now()]);

    Livewire::test(ListStockMovements::class)
        ->assertCanSeeTableRecords([$newer, $older]);
});

test('requested stock movements expose a ship action', function (): void {
    $movement = StockMovement::factory()->create();

    Livewire::test(ListStockMovements::class)
        ->assertTableActionExists('ship', record: $movement)
        ->mountTableAction('ship', $movement)
        ->assertActionMounted();
});

test('shipped stock movements expose receive and reject actions', function (): void {
    $movement = StockMovement::factory()->create(['status' => 'shipped']);

    Livewire::test(ListStockMovements::class)
        ->assertTableActionExists('receive', record: $movement)
        ->assertTableActionExists('rejectDispatch', record: $movement);
});

test('stock movement table exposes a clone action', function (): void {
    $movement = StockMovement::factory()->create();

    Livewire::test(ListStockMovements::class)
        ->assertTableActionExists('clone', record: $movement);
});

test('stock movement clone creates a fresh requested movement with its items', function (): void {
    $movement = StockMovement::factory()->create([
        'notes' => 'Repeat this transfer',
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'quantity' => 4,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $movement->from_warehouse_id,
        'product_variant_id' => $item->product_variant_id,
        'stock_available' => 10,
    ]);

    Livewire::test(ListStockMovements::class)
        ->callTableAction('clone', $movement, data: [
            'movement_date' => now()->toDateString(),
            'from_warehouse_id' => $movement->from_warehouse_id,
            'to_warehouse_id' => $movement->to_warehouse_id,
            'requested_by' => $this->actor->id,
            'notes' => 'Cloned transfer',
        ])
        ->assertHasNoActionErrors();

    $clone = StockMovement::query()
        ->whereKeyNot($movement->id)
        ->where('notes', 'Cloned transfer')
        ->firstOrFail();

    expect($clone->status->value)->toBe('requested')
        ->and($clone->notes)->toBe('Cloned transfer')
        ->and($clone->items()->firstOrFail()->quantity)->toBe($item->quantity)
        ->and($clone->reserved_at)->not->toBeNull()
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $movement->from_warehouse_id)
            ->where('product_variant_id', $item->product_variant_id)
            ->value('stock_reserved'))->toBe('4.000');
});

test('stock movement clone can save as draft without reserving stock', function (): void {
    $movement = StockMovement::factory()->create([
        'notes' => 'Prepare a transfer draft',
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'quantity' => 4,
    ]);
    $sourceStock = WarehouseStock::factory()->create([
        'warehouse_id' => $movement->from_warehouse_id,
        'product_variant_id' => $item->product_variant_id,
        'stock_available' => 10,
        'stock_reserved' => 0,
    ]);

    $list = Livewire::test(ListStockMovements::class);
    $footerActions = CloneStockMovementAction::make()
        ->livewire($list->instance())
        ->getExtraModalFooterActions();

    expect($footerActions)
        ->toHaveKey('saveDraft')
        ->and($footerActions['saveDraft']->getLabel())->toBe('Save as Draft');

    Livewire::test(ListStockMovements::class)
        ->callTableAction('clone', $movement, data: [
            'movement_date' => now()->toDateString(),
            'from_warehouse_id' => $movement->from_warehouse_id,
            'to_warehouse_id' => $movement->to_warehouse_id,
            'requested_by' => $this->actor->id,
            'notes' => 'Cloned transfer draft',
            'items' => [
                [
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => 4,
                    'unit' => $item->unit,
                ],
            ],
        ], arguments: ['draft' => true])
        ->assertHasNoTableActionErrors();

    $clone = StockMovement::query()
        ->whereKeyNot($movement->id)
        ->where('notes', 'Cloned transfer draft')
        ->firstOrFail();

    expect($clone->status)->toBe(StockMovementStatus::Draft)
        ->and($clone->approval_status)->toBe(StockMovementApprovalStatus::Pending)
        ->and($clone->reserved_at)->toBeNull()
        ->and($clone->items()->firstOrFail()->quantity)->toBe('4.000')
        ->and($sourceStock->refresh()->stock_available)->toBe('10.000')
        ->and($sourceStock->stock_reserved)->toBe('0.000');
});

test('stock movement detail is a standalone page with workflow details', function (): void {
    $movement = StockMovement::factory()->create([
        'notes' => 'Handle with care',
        'status' => 'shipped',
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'quantity' => 12,
        'quantity_shipped' => 10,
        'quantity_received' => null,
        'unit' => 'pcs',
    ]);

    $page = Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertSee('View Stock Movement')
        ->assertSee('Progress')
        ->assertSee('Requested')
        ->assertSee('Level 1')
        ->assertSee('Approved')
        ->assertSee('Shipped')
        ->assertSee('In Transit')
        ->assertSee('Received')
        ->assertSee('Movement Information')
        ->assertSee('Approval Status')
        ->assertSee('Notes')
        ->assertSee('Handle with care')
        ->assertSee('Items')
        ->assertSee('Qty Request')
        ->assertSee('Qty Shipped')
        ->assertSee('Qty Receive')
        ->assertSee(Quantity::format($item->quantity))
        ->assertSee(Quantity::format($item->quantity_shipped))
        ->assertSee($item->unit)
        ->assertDontSee('Submit');

    expect($page->instance()->getMaxContentWidth())->toBe(Width::Full);
});

test('rejected stock movement detail shows rejected as the approval status', function (): void {
    $movement = StockMovement::factory()->create([
        'status' => StockMovementStatus::Cancelled,
        'approval_status' => StockMovementApprovalStatus::Rejected,
    ]);

    $page = Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertSee('Approval Status')
        ->assertSee('Rejected');

    $infolist = $page->instance()->getSchema('infolist');

    expect($infolist->getComponent('status')->getColor($movement->status))
        ->toBe('gray')
        ->and($infolist->getComponent('approval_status')->getColor($movement->approval_status))
        ->toBe('danger');
});

test('cancelled movement badges and cancel action use gray', function (): void {
    $movement = StockMovement::factory()->create([
        'status' => StockMovementStatus::Cancelled,
        'approval_status' => StockMovementApprovalStatus::Cancelled,
    ]);

    $page = Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertActionExists('cancel');
    $infolist = $page->instance()->getSchema('infolist');

    expect($infolist->getComponent('status')->getColor($movement->status))
        ->toBe('gray')
        ->and($infolist->getComponent('approval_status')->getColor($movement->approval_status))
        ->toBe('gray')
        ->and($page->instance()->getAction('cancel')->getColor())
        ->toBe('gray');
});

test('requested stock movement detail exposes the ship action', function (): void {
    $movement = StockMovement::factory()->create();

    Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertActionVisible('ship');
});

test('admin can process the approval workflow from the movement detail', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    Permission::findOrCreate('View:StockMovement', 'web');
    Permission::findOrCreate('ViewAny:StockMovement', 'web');
    Permission::findOrCreate('Cancel:StockMovement', 'web');
    Permission::findOrCreate('Ship:StockMovement', 'web');
    $admin->givePermissionTo('View:StockMovement', 'ViewAny:StockMovement', 'Cancel:StockMovement', 'Ship:StockMovement');

    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    ApprovalSettingLevel::factory()->count(2)->create(['approval_setting_id' => $setting->id]);
    $movement = StockMovement::factory()->create(['reserved_at' => now()]);

    $this->actingAs($admin);

    Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('approve')
        ->assertActionVisible('rejectApproval')
        ->assertActionVisible('reviseApproval')
        ->assertActionVisible('cancel')
        ->assertActionDoesNotExist('edit')
        ->assertActionHidden('ship')
        ->assertActionHidden('receive')
        ->assertActionHidden('rejectDispatch')
        ->callAction('approve')
        ->assertActionVisible('approve')
        ->assertActionVisible('rejectApproval')
        ->assertActionVisible('reviseApproval')
        ->assertActionHidden('cancel')
        ->assertActionHidden('ship')
        ->callAction('approve')
        ->assertActionVisible('ship');
});

test('pending approval stock movements hide the ship action', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $movement = StockMovement::factory()->create([
        'approval_status' => StockMovementApprovalStatus::Pending,
        'approval_level' => 1,
    ]);

    Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertActionHidden('ship');
});

test('ship appears after the final approval refreshes the detail page', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $movement = StockMovement::factory()->create();

    Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertActionHidden('ship')
        ->callAction('approve')
        ->assertActionVisible('ship');
});

test('the movement detail refreshes its actions after a status update', function (): void {
    $movement = StockMovement::factory()->create([
        'status' => StockMovementStatus::Approved,
    ]);

    $page = Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertActionVisible('ship')
        ->assertActionHidden('receive');

    $movement->forceFill([
        'status' => StockMovementStatus::Shipped,
        'shipped_at' => now(),
    ])->save();

    $page->dispatch('refresh-page')
        ->assertActionHidden('ship')
        ->assertActionVisible('receive');
});

test('ship uses a distinct action color from clone', function (): void {
    $movement = StockMovement::factory()->create();

    Livewire::test(ListStockMovements::class)
        ->assertTableActionHasColor('ship', 'success', $movement)
        ->assertTableActionHasColor('clone', 'primary', $movement);
});

test('revised stock movements do not expose cancel', function (): void {
    $movement = StockMovement::factory()->create([
        'status' => StockMovementStatus::Requested,
        'approval_status' => StockMovementApprovalStatus::Revised,
        'reserved_at' => now(),
    ]);

    Livewire::test(ListStockMovements::class)
        ->assertTableActionDoesNotExist('cancel')
        ->assertTableActionVisible('edit', $movement);

    Livewire::test(ViewStockMovement::class, ['record' => $movement->getRouteKey()])
        ->assertActionDoesNotExist('edit');
});

test('stock movement create form requires at least one addable item', function (): void {
    Livewire::test(ListStockMovements::class)
        ->mountAction('create')
        ->assertFormFieldExists('movement_number')
        ->assertFormFieldExists('movement_date')
        ->assertFormFieldExists('from_warehouse_id')
        ->assertFormFieldExists('to_warehouse_id')
        ->assertFormFieldExists('requested_by')
        ->assertFormFieldExists('notes')
        ->assertFormFieldExists('items')
        ->assertActionExists('saveDraft');
});

test('stock movement create stores any number of items', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $firstVariant = ProductVariant::factory()->create();
    $secondVariant = ProductVariant::factory()->create();
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $firstVariant->id,
        'stock_available' => 10,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $secondVariant->id,
        'stock_available' => 10,
    ]);

    Livewire::test(ListStockMovements::class)
        ->callAction('create', data: [
            'movement_date' => now()->toDateString(),
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'requested_by' => $this->actor->id,
            'notes' => 'Multi-item movement request',
            'items' => [
                [
                    'product_variant_id' => $firstVariant->id,
                    'quantity' => 3,
                    'unit' => $firstVariant->unit->code,
                ],
                [
                    'product_variant_id' => $secondVariant->id,
                    'quantity' => 8,
                    'unit' => $secondVariant->unit->code,
                ],
            ],
        ])
        ->assertHasNoActionErrors();

    $movement = StockMovement::query()->latest('created_at')->firstOrFail();

    expect($movement->items()->count())->toBe(2)
        ->and($movement->status->value)->toBe('requested');
});

test('stock movement create shows warehouse stock and snapshots the variant unit', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 14,
    ]);

    Livewire::test(ListStockMovements::class)
        ->callAction('create', data: [
            'movement_date' => now()->toDateString(),
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'requested_by' => $this->actor->id,
            'items' => [[
                'product_variant_id' => $variant->id,
                'quantity' => 6,
                'unit' => 'incorrect-unit',
            ]],
        ])
        ->assertHasNoActionErrors();

    $item = StockMovement::query()->latest('created_at')->firstOrFail()->items()->firstOrFail();

    expect($item->unit)->toBe($variant->unit->code);
});

test('stock movement create rejects quantity above warehouse stock', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 4,
    ]);

    Livewire::test(ListStockMovements::class)
        ->callAction('create', data: [
            'movement_date' => now()->toDateString(),
            'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id,
            'requested_by' => $this->actor->id,
            'items' => [[
                'product_variant_id' => $variant->id,
                'quantity' => 5,
                'unit' => $variant->unit->code,
            ]],
        ])
        ->assertHasActionErrors(['items.0.quantity']);

    expect(StockMovement::query()->count())->toBe(0);
});
