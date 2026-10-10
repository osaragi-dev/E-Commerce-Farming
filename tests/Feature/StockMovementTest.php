<?php

use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Enums\StockMutationSource;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use App\Models\StockMovementLog;
use App\Models\StockMutation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\ApprovalWorkflow;
use Illuminate\Validation\ValidationException;

test('stock movement transfers available stock and writes both warehouse audits', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-MOVEMENT-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-MOVEMENT-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 20]);

    $source = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 12,
    ]);
    $movement = StockMovement::transfer($from, $to, $variant, 5, 'Routine replenishment');

    expect($movement->from_warehouse_id)->toBe($from->id)
        ->and($movement->to_warehouse_id)->toBe($to->id)
        ->and($movement->movement_number)->toStartWith('SM-')
        ->and($movement->approval_status)->toBe(StockMovementApprovalStatus::Approved)
        ->and($movement->status)->toBe(StockMovementStatus::Received)
        ->and(WarehouseStock::query()->where('warehouse_id', $from->id)->where('product_variant_id', $variant->id)->value('stock_available'))->toBe('7.000')
        ->and($source->refresh()->stock_out)->toBe('5.000')
        ->and(WarehouseStock::query()->where('warehouse_id', $to->id)->where('product_variant_id', $variant->id)->value('stock_available'))->toBe('5.000')
        ->and(StockMutation::query()->where('reference_id', $movement->id)->where('source', StockMutationSource::Transfer)->count())->toBe(2)
        ->and($movement->logs()->where('action', 'created')->exists())->toBeTrue()
        ->and($variant->refresh()->stock_available)->toBe('20.000');
});

test('stock movement records without approval levels are auto-approved', function (): void {
    $movement = StockMovement::factory()->create();

    expect($movement->movement_number)->toStartWith('SM-')
        ->and($movement->approval_status)->toBe(StockMovementApprovalStatus::Approved)
        ->and($movement->status)->toBe(StockMovementStatus::Requested)
        ->and(StockMovementLog::query()->where('stock_movement_id', $movement->id)->count())->toBe(1);
});

test('new stock movements request approval when approval levels are configured', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $movement = StockMovement::factory()->create();

    expect($movement->approval_status)->toBe(StockMovementApprovalStatus::Requested)
        ->and($movement->approval_level)->toBe(1);
});

test('stock movement draft has no number and requested records use the qa number format', function (): void {
    $draft = StockMovement::factory()->create(['status' => StockMovementStatus::Draft]);
    $saved = StockMovement::factory()->create(['status' => StockMovementStatus::Requested]);

    expect($draft->movement_number)->toBeNull()
        ->and($draft->approval_status)->toBe(StockMovementApprovalStatus::Pending)
        ->and($saved->movement_number)->toMatch('/^SM-\d{8}-[A-Z0-9]{12}$/');
});

test('saved movement numbers are normalized when a legacy value is updated', function (): void {
    $movement = StockMovement::factory()->create([
        'movement_number' => 'LEGACY-MOVEMENT-001',
    ]);

    $movement->forceFill(['updated_by' => auth()->id()])->save();

    expect($movement->refresh()->movement_number)->toMatch('/^SM-\d{8}-[A-Z0-9]{12}$/');
});

test('submitting a draft movement reserves its source stock', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-DRAFT-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-DRAFT-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 8]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 8,
    ]);
    $movement = StockMovement::factory()->create([
        'status' => StockMovementStatus::Draft,
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);
    StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    $movement->submit();
    $stock = WarehouseStock::query()
        ->where('warehouse_id', $from->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Requested)
        ->and($movement->movement_number)->toStartWith('SM-')
        ->and($stock->stock_available)->toBe('6.000')
        ->and($stock->stock_reserved)->toBe('2.000');
});

test('cancelling a reserved movement returns stock to available', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-CANCEL-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-CANCEL-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
        'product_variant_id' => $variant->id,
        'quantity' => 3,
    ]);
    StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 3,
    ]);

    $movement->reserveRequestedStock();
    $movement->cancel('Customer cancelled transfer');

    $stock = WarehouseStock::query()
        ->where('warehouse_id', $from->id)
        ->where('product_variant_id', $variant->id)
        ->firstOrFail();
    $cancelMutation = StockMutation::query()
        ->where('reference_id', $movement->id)
        ->where('source', 'cancel_transfer')
        ->sole();

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Cancelled)
        ->and($movement->approval_status)->toBe(StockMovementApprovalStatus::Cancelled)
        ->and($cancelMutation->source->getLabel())->toBe('Cancel Transfer')
        ->and(StockMutation::query()
            ->where('reference_id', $movement->id)
            ->where('source', StockMutationSource::Transfer)
            ->count())->toBe(1)
        ->and($stock->stock_available)->toBe('10.000')
        ->and($stock->stock_reserved)->toBe('0.000')
        ->and($variant->refresh()->stock_available)->toBe('10.000')
        ->and($variant->stock_reserved)->toBe('0.000');
});

test('stock movement requires each configured approval level before shipping', function (): void {
    $role = Role::query()->create([
        'name' => 'Movement Approver',
        'code' => 'movement-approver',
        'guard_name' => 'web',
    ]);
    $approver = User::factory()->create();
    $approver->assignRole($role);
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]);
    $movement = StockMovement::factory()->create();

    expect(app(ApprovalWorkflow::class)->canApprove($movement, $approver))->toBeTrue();

    app(ApprovalWorkflow::class)->approve($movement, $approver);

    expect($movement->refresh()->approval_status)->toBe(StockMovementApprovalStatus::Approved);
});

test('stock movement cannot ship while approval is pending', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);
    $movement = StockMovement::factory()->create([
        'approval_status' => StockMovementApprovalStatus::Pending,
        'approval_level' => 1,
    ]);

    expect(fn (): StockMovement => $movement->ship([]))
        ->toThrow(ValidationException::class, 'must be approved before it can be shipped');
});

test('stock movement approvals advance through configured levels', function (): void {
    $firstRole = Role::query()->create([
        'name' => 'Movement Reviewer',
        'code' => 'movement-reviewer',
        'guard_name' => 'web',
    ]);
    $secondRole = Role::query()->create([
        'name' => 'Movement Manager',
        'code' => 'movement-manager',
        'guard_name' => 'web',
    ]);
    $firstApprover = User::factory()->create();
    $secondApprover = User::factory()->create();
    $firstApprover->assignRole($firstRole);
    $secondApprover->assignRole($secondRole);
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $firstRole->id,
    ]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $secondRole->id,
    ]);
    $movement = StockMovement::factory()->create();
    $workflow = app(ApprovalWorkflow::class);

    $workflow->approve($movement, $firstApprover);

    expect($movement->refresh()->approval_status)->toBe(StockMovementApprovalStatus::Pending)
        ->and($movement->approval_level)->toBe(2)
        ->and($workflow->canApprove($movement, $firstApprover))->toBeFalse()
        ->and($workflow->canApprove($movement, $secondApprover))->toBeTrue();

    $workflow->approve($movement, $secondApprover);

    expect($movement->refresh()->approval_status)->toBe(StockMovementApprovalStatus::Approved);
});

test('admin can approve every configured stock movement level', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();
    ApprovalSettingLevel::factory()->count(2)->create([
        'approval_setting_id' => $setting->id,
    ]);
    $movement = StockMovement::factory()->create();
    $workflow = app(ApprovalWorkflow::class);

    expect($movement->approval_status)->toBe(StockMovementApprovalStatus::Requested)
        ->and($workflow->canApprove($movement, $admin))->toBeTrue();

    $workflow->approve($movement, $admin);

    expect($movement->refresh()->approval_status)->toBe(StockMovementApprovalStatus::Pending)
        ->and($movement->approval_level)->toBe(2)
        ->and($workflow->canApprove($movement, $admin))->toBeTrue();

    $workflow->approve($movement, $admin);

    expect($movement->refresh()->approval_status)->toBe(StockMovementApprovalStatus::Approved);
});

test('stock movement approval revision returns the workflow to level one', function (): void {
    $role = Role::query()->create([
        'name' => 'Movement Revision Manager',
        'code' => 'movement-revision-manager',
        'guard_name' => 'web',
    ]);
    $approver = User::factory()->create();
    $approver->assignRole($role);
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]);
    $movement = StockMovement::factory()->create();
    $workflow = app(ApprovalWorkflow::class);

    $workflow->revise($movement, $approver, 'Correct the requested quantity.');

    expect($movement->refresh()->approval_status)->toBe(StockMovementApprovalStatus::Revised)
        ->and($movement->approval_level)->toBe(1)
        ->and($movement->approvalLogs()->where('action', 'revised')->exists())->toBeTrue();
});

test('rejecting an approved movement releases reserved source stock', function (): void {
    $role = Role::query()->create([
        'name' => 'Movement Rejector',
        'code' => 'movement-rejector',
        'guard_name' => 'web',
    ]);
    $approver = User::factory()->create();
    $approver->assignRole($role);
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]);

    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $sourceStock = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 6,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    $movement->reserveRequestedStock();
    app(ApprovalWorkflow::class)->reject($movement, $approver, 'Insufficient business justification.');

    expect($movement->refresh()->approval_status)->toBe(StockMovementApprovalStatus::Rejected)
        ->and($movement->status)->toBe(StockMovementStatus::Cancelled)
        ->and($sourceStock->refresh()->stock_available)->toBe('6.000')
        ->and($sourceStock->stock_reserved)->toBe('0.000')
        ->and($movement->reserved_at)->toBeNull();
});

test('stock movement supports multiple request items', function (): void {
    $movement = StockMovement::factory()->create();
    $items = StockMovementItem::factory()->count(3)->create([
        'stock_movement_id' => $movement->id,
    ]);

    expect($movement->items()->count())->toBe(3)
        ->and($items)->toHaveCount(3);
});

test('stock movement ships requested quantities and deducts source stock', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-SHIP-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-SHIP-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 20]);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 12,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 5,
    ]);

    $movement->ship([
        [
            'item_id' => $item->id,
            'quantity_shipped' => 4,
            'notes' => 'One unit held back.',
        ],
    ]);

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Shipped)
        ->and($movement->shipped_at)->not->toBeNull()
        ->and($movement->items()->firstOrFail()->quantity_shipped)->toBe('4.000')
        ->and($stock->refresh()->stock_available)->toBe('8.000')
        ->and($movement->logs()->where('to_status', StockMovementStatus::Shipped->value)->exists())->toBeTrue();
});

test('stock movement rejects shipped quantity above requested quantity', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-SHIP-LIMIT-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-SHIP-LIMIT-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 20]);
    $stock = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 12,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 5,
    ]);

    expect(fn () => $movement->ship([
        [
            'item_id' => $item->id,
            'quantity_shipped' => 6,
        ],
    ]))->toThrow(ValidationException::class);

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Requested)
        ->and($stock->refresh()->stock_available)->toBe('12.000')
        ->and($item->refresh()->quantity_shipped)->toBeNull();
});

test('stock movement requires a reason for shipped quantity discrepancies', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 5,
    ]);

    expect(fn () => $movement->ship([
        [
            'item_id' => $item->id,
            'quantity_shipped' => 4,
        ],
    ]))->toThrow(ValidationException::class);

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Requested)
        ->and($item->refresh()->quantity_shipped)->toBeNull();
});

test('stock movement receives shipped quantities into the destination warehouse', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-RECEIVE-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-RECEIVE-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 20]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 12,
    ]);
    $destinationStock = WarehouseStock::factory()->create([
        'warehouse_id' => $to->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 2,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
        'status' => StockMovementStatus::Shipped,
        'shipped_at' => now(),
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 5,
        'quantity_shipped' => 4,
    ]);

    $movement->receive([
        [
            'item_id' => $item->id,
            'quantity_received' => 3,
            'notes' => 'One unit was damaged in transit.',
        ],
    ]);

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Received)
        ->and($movement->received_at)->not->toBeNull()
        ->and($movement->items()->firstOrFail()->quantity_received)->toBe('3.000')
        ->and($destinationStock->refresh()->stock_available)->toBe('5.000');
});

test('stock movement rejection returns shipped stock to reserved and requested', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-REJECT-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-REJECT-TO']);
    $variant = ProductVariant::factory()->create([
        'stock_available' => 20,
        'stock_reserved' => 0,
        'stock_in_transit' => 4,
    ]);
    $sourceStock = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 8,
        'stock_reserved' => 0,
        'stock_in_transit' => 4,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
        'status' => StockMovementStatus::Shipped,
        'shipped_at' => now(),
        'reserved_at' => now(),
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 5,
        'quantity_shipped' => 4,
    ]);

    $movement->rejectDispatch('Carrier damaged the shipment.');

    expect($movement->refresh()->status)->toBe(StockMovementStatus::Requested)
        ->and($movement->shipped_at)->toBeNull()
        ->and($sourceStock->refresh()->stock_available)->toBe('7.000')
        ->and($sourceStock->stock_reserved)->toBe('5.000')
        ->and($sourceStock->stock_in_transit)->toBe('0.000')
        ->and($item->refresh()->quantity_shipped)->toBeNull()
        ->and($movement->notes)->toBe('Carrier damaged the shipment.');
});

test('rejecting a partial dispatch restores the full requested reservation', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $source = WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $movement = StockMovement::factory()->create([
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
    ]);
    $item = StockMovementItem::factory()->create([
        'stock_movement_id' => $movement->id,
        'product_variant_id' => $variant->id,
        'quantity' => 4,
    ]);

    $movement->reserveRequestedStock();
    $movement->ship([['item_id' => $item->id, 'quantity_shipped' => 3, 'notes' => 'One unit held back.']]);
    $movement->refresh()->rejectDispatch('Carrier returned the shipment.');

    expect($source->refresh()->stock_available)->toBe('6.000')
        ->and($source->stock_reserved)->toBe('4.000')
        ->and($source->stock_in_transit)->toBe('0.000')
        ->and($source->stock_out)->toBe('0.000')
        ->and($item->refresh()->quantity_shipped)->toBeNull();
});

test('stock movement rejects insufficient source stock without changing either warehouse', function (): void {
    $from = Warehouse::factory()->create(['code' => 'WH-MOVEMENT-LOW-FROM']);
    $to = Warehouse::factory()->create(['code' => 'WH-MOVEMENT-LOW-TO']);
    $variant = ProductVariant::factory()->create(['stock_available' => 20]);

    WarehouseStock::factory()->create([
        'warehouse_id' => $from->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 2,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $to->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 3,
    ]);

    expect(fn () => StockMovement::transfer($from, $to, $variant, 5))
        ->toThrow(ValidationException::class);

    expect(WarehouseStock::query()->where('warehouse_id', $from->id)->where('product_variant_id', $variant->id)->value('stock_available'))->toBe('2.000')
        ->and(WarehouseStock::query()->where('warehouse_id', $to->id)->where('product_variant_id', $variant->id)->value('stock_available'))->toBe('3.000')
        ->and(StockMovement::query()->count())->toBe(0);
});

test('stock movement rejects the same source and destination warehouse', function (): void {
    $warehouse = Warehouse::factory()->create(['code' => 'WH-MOVEMENT-SAME']);
    $variant = ProductVariant::factory()->create();

    expect(fn () => StockMovement::transfer($warehouse, $warehouse, $variant, 1))
        ->toThrow(ValidationException::class);
});
