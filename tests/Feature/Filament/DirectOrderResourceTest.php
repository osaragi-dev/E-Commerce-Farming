<?php

use App\Enums\ApprovalModule;
use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\DirectOrders\DirectOrderResource;
use App\Filament\Resources\DirectOrders\Pages\CreateDirectOrder;
use App\Filament\Resources\DirectOrders\Pages\EditDirectOrder;
use App\Filament\Resources\DirectOrders\Pages\ManageDirectOrders;
use App\Filament\Resources\DirectOrders\Pages\ViewDirectOrder;
use App\Filament\Resources\DirectOrders\Schemas\DirectOrderForm;
use App\Filament\Resources\DirectOrders\Support\DirectOrderDetailPresenter;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\DirectOrder;
use App\Models\DirectOrderItem;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ApprovalWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actor->forceFill(['sso_id' => (string) Str::uuid()])->save();
    $this->actingAs($this->actor);
    Storage::fake('public');
});

test('direct orders generate a number when saved and follow the lifecycle', function (): void {
    $directOrder = DirectOrder::factory()->create();

    expect($directOrder->do_number)->toStartWith('DO-'.now()->format('Ymd').'-')
        ->and($directOrder->record_status)->toBe(RecordStatus::Saved)
        ->and($directOrder->approval_status)->toBe(PurchaseRequestApprovalStatus::Approved)
        ->and($directOrder->markAsDraft())->toBeTrue()
        ->and($directOrder->fresh()->record_status)->toBe(RecordStatus::Draft);
});

test('direct order drafts skip numbering and approval requests', function (): void {
    $draft = DirectOrder::factory()->create(['record_status' => RecordStatus::Draft]);

    expect($draft->do_number)->toBeNull()
        ->and($draft->approval_status)->toBe(PurchaseRequestApprovalStatus::Pending)
        ->and($draft->approval_level)->toBe(0)
        ->and($draft->approval_setting_id)->toBeNull()
        ->and($draft->approvalLogs()->exists())->toBeFalse();
});

test('direct orders support the same multi-level approval progression as purchase requests', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::DirectOrder->value)->firstOrFail();
    $setting->forceFill([
        'module_code' => ApprovalModule::DirectOrder->value,
        'is_active' => true,
    ])->save();
    ApprovalSettingLevel::factory()->count(2)->sequence(
        ['level' => 1],
        ['level' => 2],
    )->create(['approval_setting_id' => $setting->id]);

    $directOrder = DirectOrder::factory()->create();

    expect($directOrder->approval_status)->toBe(PurchaseRequestApprovalStatus::Requested)
        ->and($directOrder->approval_level)->toBe(1)
        ->and($directOrder->approvalLogs()->where('action', 'requested')->exists())->toBeTrue();

    app(ApprovalWorkflow::class)->approve($directOrder, $this->actor);
    $afterFirstLevel = $directOrder->fresh();

    expect($afterFirstLevel->approval_status)->toBe(PurchaseRequestApprovalStatus::Pending)
        ->and($afterFirstLevel->approvalProgressLabel())->toBe('L1 Approved');

    app(ApprovalWorkflow::class)->approve($afterFirstLevel, $this->actor);

    expect($directOrder->fresh()->approval_status)->toBe(PurchaseRequestApprovalStatus::Approved)
        ->and($directOrder->approvalLogs()->where('action', 'approved')->count())->toBe(2);
});

test('direct order form persists selected items and computes amount totals', function (): void {
    $warehouse = Warehouse::factory()->create();
    $vendor = Vendor::factory()->create();
    $item = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'initial_stock' => 2,
        'min_stock' => 5,
    ]);

    Livewire::test(CreateDirectOrder::class)
        ->fillForm([
            'do_date' => now()->toDateString(),
            'payment_by' => 'Holding',
            'reason' => 'Urgent supply',
            'warehouse_id' => $warehouse->id,
            'vendor_id' => $vendor->id,
            'requested_by' => $this->actor->id,
            'items' => [[
                'item_id' => $item->id,
                'selected' => true,
                'quantity' => 3,
                'unit_price' => '10.000,00',
                'notes' => 'Deliver fresh',
            ]],
            'tax_amount' => '1.000,00',
            'discount_amount' => '500,00',
            'terms_conditions' => 'Pay within 30 days.',
            'transaction_proof' => ['direct-order-proofs/proof.pdf'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $directOrder = DirectOrder::query()->with('items')->sole();

    expect($directOrder->do_number)->toStartWith('DO-')
        ->and($directOrder->subtotal)->toBe('30000.00')
        ->and($directOrder->tax_amount)->toBe('1000.00')
        ->and($directOrder->discount_amount)->toBe('500.00')
        ->and($directOrder->total_amount)->toBe('30500.00')
        ->and($directOrder->items)->toHaveCount(1)
        ->and($directOrder->items->first()->quantity)->toBe('3.000')
        ->and($directOrder->items->first()->unit_price)->toBe('10000.00')
        ->and($directOrder->items->first()->total_price)->toBe('30000.00');
});

test('direct order line totals and subtotal update when quantity or unit price changes', function (): void {
    $warehouse = Warehouse::factory()->create();
    Item::factory()->create(['initial_warehouse_id' => $warehouse->id]);

    Livewire::test(CreateDirectOrder::class)
        ->fillForm(['warehouse_id' => $warehouse->id])
        ->set('data.items.0.selected', true)
        ->set('data.items.0.quantity', '2')
        ->set('data.items.0.unit_price', '1.500,50')
        ->assertSet('data.items.0.total_price', '3.001,00')
        ->assertSet('data.subtotal', '3.001,00')
        ->set('data.items.0.quantity', '3')
        ->assertSet('data.items.0.total_price', '4.501,50')
        ->assertSet('data.subtotal', '4.501,50')
        ->set('data.items.0.unit_price', '2.500,25')
        ->assertSet('data.items.0.total_price', '7.500,75')
        ->assertSet('data.subtotal', '7.500,75');
});

test('direct order subtotal adds rounded Total Price values for each selected item', function (): void {
    $warehouse = Warehouse::factory()->create();
    Item::factory()->count(2)->create([
        'initial_warehouse_id' => $warehouse->id,
        'initial_stock' => 2,
        'min_stock' => 5,
    ]);

    Livewire::test(CreateDirectOrder::class)
        ->fillForm(['warehouse_id' => $warehouse->id])
        ->set('data.items.0.selected', true)
        ->set('data.items.0.quantity', '0.001')
        ->set('data.items.0.unit_price', '5,00')
        ->set('data.items.1.selected', true)
        ->set('data.items.1.quantity', '0.001')
        ->set('data.items.1.unit_price', '5,00')
        ->assertSet('data.items.0.total_price', '0,01')
        ->assertSet('data.items.1.total_price', '0,01')
        ->assertSet('data.subtotal', '0,02');
});

test('direct order rejects item selections from a different location', function (): void {
    $warehouse = Warehouse::factory()->create();
    $otherWarehouse = Warehouse::factory()->create();
    $vendor = Vendor::factory()->create();
    $item = Item::factory()->create(['initial_warehouse_id' => $otherWarehouse->id]);

    Livewire::test(CreateDirectOrder::class)
        ->fillForm([
            'do_date' => now()->toDateString(),
            'payment_by' => 'Farming',
            'reason' => 'Restock',
            'warehouse_id' => $warehouse->id,
            'vendor_id' => $vendor->id,
            'requested_by' => $this->actor->id,
            'items' => [[
                'item_id' => $item->id,
                'selected' => true,
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
            'transaction_proof' => ['direct-order-proofs/proof.pdf'],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(DirectOrder::query()->exists())->toBeFalse();
});

test('direct order item tabs filter critical and not critical stock at the selected location', function (): void {
    $warehouse = Warehouse::factory()->create();
    $critical = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'name' => 'C low stock',
        'initial_stock' => 2,
        'min_stock' => 5,
    ]);
    $notCritical = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'name' => 'B high stock',
        'initial_stock' => 10,
        'min_stock' => 5,
    ]);
    $atMinimum = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'name' => 'A equal stock',
        'initial_stock' => 5,
        'min_stock' => 5,
    ]);

    Livewire::test(CreateDirectOrder::class)
        ->fillForm(['warehouse_id' => $warehouse->id])
        ->assertSet('data.items.0.item_id', $critical->id)
        ->assertSet('data.items.0.status', 'Critical')
        ->assertSee('purchase-request-status-badge--critical')
        ->set('data.item_category', 'not_critical')
        ->assertSet('data.items.0.item_id', $atMinimum->id)
        ->assertSet('data.items.0.status', 'OK')
        ->assertSet('data.items.1.item_id', $notCritical->id)
        ->assertSet('data.items.1.status', 'OK')
        ->assertSee('purchase-request-status-badge--ok');
});

test('direct order edit classifies an item at minimum stock as OK', function (): void {
    $directOrder = DirectOrder::factory()->create(['record_status' => RecordStatus::Draft]);
    $item = Item::factory()->create([
        'initial_warehouse_id' => $directOrder->warehouse_id,
        'initial_stock' => 5,
        'min_stock' => 5,
    ]);
    DirectOrderItem::factory()->create([
        'direct_order_id' => $directOrder->id,
        'item_id' => $item->id,
    ]);

    $rows = DirectOrderForm::rowsForEdit($directOrder);

    expect($rows[0]['item_id'])->toBe($item->id)
        ->and($rows[0]['status'])->toBe('OK');
});

test('direct order tab switching preserves selected values and shows only the active status group', function (): void {
    $warehouse = Warehouse::factory()->create();
    $critical = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'initial_stock' => 2,
        'min_stock' => 5,
    ]);
    $notCritical = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'initial_stock' => 10,
        'min_stock' => 5,
    ]);

    Livewire::test(CreateDirectOrder::class)
        ->fillForm(['warehouse_id' => $warehouse->id])
        ->set('data.items.0.selected', true)
        ->set('data.items.0.quantity', '2')
        ->set('data.items.0.unit_price', '1.000,00')
        ->set('data.items.0.notes', 'Keep this note')
        ->set('data.item_category', 'not_critical')
        ->set('data.item_search', $notCritical->code)
        ->assertSee('direct-order-checklist--not_critical')
        ->assertSee('purchase-request-status-badge--critical')
        ->assertSee('purchase-request-status-badge--ok')
        ->assertSet('data.items.0.item_id', $critical->id)
        ->assertSet('data.items.0.quantity', '2')
        ->assertSet('data.items.0.unit_price', '1.000,00')
        ->assertSet('data.items.0.notes', 'Keep this note')
        ->assertSet('data.items.1.item_id', $notCritical->id)
        ->assertSet('data.subtotal', '2.000,00');
});

test('direct order transaction proof is required', function (): void {
    $warehouse = Warehouse::factory()->create();
    $vendor = Vendor::factory()->create();
    $item = Item::factory()->create(['initial_warehouse_id' => $warehouse->id]);

    Livewire::test(CreateDirectOrder::class)
        ->fillForm([
            'do_date' => now()->toDateString(),
            'payment_by' => 'Holding',
            'reason' => 'Urgent supply',
            'warehouse_id' => $warehouse->id,
            'vendor_id' => $vendor->id,
            'requested_by' => $this->actor->id,
            'items' => [[
                'item_id' => $item->id,
                'selected' => true,
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(DirectOrder::query()->exists())->toBeFalse();
});

test('direct orders expose the required list columns and standard export actions', function (): void {
    $component = Livewire::test(ManageDirectOrders::class)->assertOk();
    $columns = collect($component->instance()->getTable()->getColumns());
    $labels = $columns->map(fn ($column): string => $column->getLabel())->all();

    expect($labels)->toContain(
        'No', 'Approval', 'Progress', 'DO Number', 'DO Date', 'Location', 'Vendor', 'Total Amount',
        'Status', 'Log History', 'Notes', 'Proof', 'Created By', 'Created At', 'Updated By', 'Updated At',
    )->and($component->instance()->getTable()->getRecordActionsColumnLabel())->toBe('Action')
        ->and($component->instance()->getTable()->getPaginationPageOptions())->toBe([10, 25, 50, 100, 'all'])
        ->and($component->instance()->getTable()->getToolbarActions())->not->toBeEmpty();

    foreach (['progress', 'createdBy.name', 'created_at', 'updatedBy.name', 'updated_at'] as $columnName) {
        expect($columns[$columnName]->isToggledHiddenByDefault())->toBeFalse();
    }

    $definition = DirectOrderResource::exportDefinition();

    expect(array_keys($definition->columns))->not->toContain('transaction_proof', 'id')
        ->and($definition->columns)->toHaveKey('do_number')
        ->and($definition->columns)->toHaveKey('progress');
});

test('direct order approval timeline and badges follow each configured level', function (int $levelCount): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::DirectOrder->value)->firstOrFail();
    ApprovalSettingLevel::factory()->count($levelCount)->create(['approval_setting_id' => $setting->id]);
    $record = DirectOrder::factory()->create();
    $presenter = app(DirectOrderDetailPresenter::class);
    $labels = ['Requested'];

    for ($level = 1; $level < $levelCount; $level++) {
        $labels[] = 'Level '.$level;
    }

    $labels[] = 'Approved';
    expect(array_column($presenter->timeline($record), 'label'))->toBe($labels);
    expect($record->approvalProgressLabel())->toBe('Requested');
    Livewire::test(ManageDirectOrders::class)->assertTableColumnStateSet('approval_status', 'Requested', $record);
    $component = Livewire::test(ViewDirectOrder::class, ['record' => $record->id]);

    for ($level = 1; $level <= $levelCount; $level++) {
        $this->travel(1)->minutes();
        $component->callAction('approve')->assertHasNoActionErrors();
        $record->refresh();
        $label = $level === $levelCount ? 'Approved' : 'L'.$level.' Approved';

        expect($record->approvalProgressLabel())->toBe($label);
        expect($record->approvalLogs()->where('action', 'approved')->count())->toBe($level);
        $component->assertSee($label)->assertSee('Detail Log');
        Livewire::test(ManageDirectOrders::class)->assertTableColumnStateSet('approval_status', $label, $record);
        $steps = $presenter->timeline($record->fresh());
        expect(array_column(array_slice($steps, 0, $level + 1), 'state'))->each->toBe('complete');
    }

    $component->assertActionHidden('approve')->assertActionHidden('reviseApproval')->assertActionHidden('rejectApproval');
    expect(array_column($presenter->timeline($record->fresh()), 'state'))->each->toBe('complete');
})->with([1, 2, 3]);

test('direct order drafts and automatic approvals have accurate progress', function (): void {
    $presenter = app(DirectOrderDetailPresenter::class);
    $draft = DirectOrder::factory()->create(['record_status' => RecordStatus::Draft]);
    $approved = DirectOrder::factory()->create();

    expect($draft->approvalProgressLabel())->toBe('Draft');
    expect(array_column($presenter->timeline($draft), 'state'))->toBe(['current', 'pending']);
    expect($presenter->timeline($draft)[0]['date'])->toBeNull();
    expect($approved->approvalProgressLabel())->toBe('Approved');
    expect(array_column($presenter->timeline($approved), 'state'))->toBe(['complete', 'complete']);
    Livewire::test(ViewDirectOrder::class, ['record' => $approved->id])->assertActionHidden('approve')->assertSee('Approved');
});

test('direct order revision starts a new timeline cycle without losing the detail log', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::DirectOrder->value)->firstOrFail();
    ApprovalSettingLevel::factory()->count(2)->create(['approval_setting_id' => $setting->id]);
    $record = DirectOrder::factory()->create();
    $component = Livewire::test(ViewDirectOrder::class, ['record' => $record->id]);
    $component->callAction('approve');
    $this->travel(1)->minutes();
    $component->callAction('reviseApproval', ['notes' => 'Please update the reason'])->assertHasNoActionErrors();

    $presenter = app(DirectOrderDetailPresenter::class);
    expect($record->fresh()->approvalProgressLabel())->toBe('Revised');
    expect(array_column($presenter->timeline($record->fresh()), 'state'))->toBe(['complete', 'stopped', 'pending']);
    $component->assertSee('Revision requested')->assertSee('Please update the reason')->assertActionHidden('approve');

    $this->travel(1)->minutes();
    $record->refresh()->update(['reason' => 'Updated reason']);
    $steps = $presenter->timeline($record->fresh());

    expect(array_column($steps, 'state'))->toBe(['complete', 'current', 'pending']);
    expect($steps[0]['time'])->toBe(now()->format('H:i'));
    expect($steps[1]['date'])->toBeNull();
    Livewire::test(ViewDirectOrder::class, ['record' => $record->id])
        ->assertActionVisible('approve')->assertSee('Please update the reason')->assertSee('Requested');
});

test('direct order rejection requires a reason and stops approval progress', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::DirectOrder->value)->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);
    $record = DirectOrder::factory()->create();
    $component = Livewire::test(ViewDirectOrder::class, ['record' => $record->id]);
    $component->callAction('rejectApproval', ['notes' => ''])->assertHasActionErrors(['notes' => 'required']);
    expect($record->fresh()->approval_status)->toBe(PurchaseRequestApprovalStatus::Requested);
    $component->unmountAction();
    $component->callAction('rejectApproval', ['notes' => 'Purchase is not required'])->assertHasNoActionErrors();

    expect($record->fresh()->approvalProgressLabel())->toBe('Rejected');
    expect(array_column(app(DirectOrderDetailPresenter::class)->timeline($record->fresh()), 'state'))->toBe(['complete', 'stopped']);
    $component->assertSee('Rejected')->assertSee('Purchase is not required')->assertActionHidden('approve');
});

test('direct order viewers without an approval role cannot make an approval decision', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::DirectOrder->value)->firstOrFail();
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);
    $record = DirectOrder::factory()->create();
    $viewer = User::factory()->create();
    $viewer->forceFill(['sso_id' => (string) Str::uuid()])->save();
    $viewer->givePermissionTo([
        Permission::findOrCreate('ViewAny:DirectOrder', 'web'),
        Permission::findOrCreate('View:DirectOrder', 'web'),
    ]);
    $this->actingAs($viewer);

    Livewire::test(ViewDirectOrder::class, ['record' => $record->id])
        ->assertOk()
        ->assertActionHidden('approve')->assertActionHidden('reviseApproval')->assertActionHidden('rejectApproval');
    expect(app(ApprovalWorkflow::class)->canApprove($record, $viewer))->toBeFalse();
    expect(fn () => app(ApprovalWorkflow::class)->approve($record, $viewer))->toThrow(ValidationException::class);
    expect($record->fresh()->approval_status)->toBe(PurchaseRequestApprovalStatus::Requested);
    expect($record->approvalLogs()->where('action', 'approved')->exists())->toBeFalse();
});

test('direct order approval detail preserves transaction data and proof links', function (): void {
    $record = DirectOrder::factory()->create([
        'payment_by' => 'Farming', 'reason' => 'Animal feed', 'terms_conditions' => 'Payment on delivery',
        'subtotal' => 20000, 'total_amount' => 20000, 'transaction_proof' => 'direct-order-proofs/proof.jpg',
    ]);
    $item = DirectOrderItem::factory()->create([
        'direct_order_id' => $record->id, 'quantity' => 2, 'unit_price' => 10000, 'total_price' => 20000,
    ]);

    Livewire::test(ViewDirectOrder::class, ['record' => $record->id])
        ->assertSee('Direct order approval progress')->assertSee('Approval Status')->assertSee('Detail Log')
        ->assertSee($record->vendor->name)->assertSee($item->item->name)->assertSee('Animal feed')
        ->assertSee('Payment on delivery')->assertSee('Rp 20.000,00')
        ->assertSee(Storage::disk('public')->url($record->transaction_proof), false);
});

test('direct order list search matches the DO number', function (): void {
    $matching = DirectOrder::factory()->create(['do_number' => 'DO-SEARCH-001']);
    $other = DirectOrder::factory()->create(['do_number' => 'DO-OTHER-001']);

    Livewire::test(ManageDirectOrders::class)
        ->searchTable('SEARCH')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('direct order create page and detail route are registered', function (): void {
    $this->get(DirectOrderResource::getUrl('create'))->assertOk();

    $directOrder = DirectOrder::factory()->create();
    $this->get(DirectOrderResource::getUrl('view', ['record' => $directOrder]))->assertOk();
    $this->get(DirectOrderResource::getUrl('edit', ['record' => $directOrder]))->assertForbidden();
});

test('direct order drafts can be saved from the full-page editor', function (): void {
    $directOrder = DirectOrder::factory()->create(['record_status' => RecordStatus::Draft]);
    $item = Item::factory()->create(['initial_warehouse_id' => $directOrder->warehouse_id]);
    DirectOrderItem::factory()->create([
        'direct_order_id' => $directOrder->id,
        'item_id' => $item->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
    ]);

    Livewire::test(EditDirectOrder::class, ['record' => $directOrder->id])
        ->fillForm([
            'do_date' => now()->toDateString(),
            'payment_by' => 'Holding',
            'reason' => 'Draft revision',
            'warehouse_id' => $directOrder->warehouse_id,
            'vendor_id' => $directOrder->vendor_id,
            'requested_by' => $this->actor->id,
            'items' => [[
                'item_id' => $item->id,
                'selected' => true,
                'quantity' => 4,
                'unit_price' => '2.500,00',
            ]],
            'tax_amount' => '0',
            'discount_amount' => '0',
            'transaction_proof' => [$directOrder->transaction_proof],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($directOrder->fresh()->record_status)->toBe(RecordStatus::Saved)
        ->and($directOrder->fresh()->do_number)->toStartWith('DO-')
        ->and($directOrder->fresh()->total_amount)->toBe('10000.00')
        ->and($directOrder->items()->sole()->quantity)->toBe('4.000');
});
