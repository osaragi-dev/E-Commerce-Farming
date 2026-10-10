<?php

use App\Enums\ApprovalModule;
use App\Enums\StockAdjustmentSource;
use App\Enums\StockAdjustmentType;
use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockAdjustments\Support\StockAdjustmentDetailPresenter;
use App\Filament\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\Pages\ViewStockOpname;
use App\Filament\Resources\StockOpnames\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\ApprovalLog;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockOpnameItem;
use App\Models\StockOpnameSession;
use App\Models\SystemFreezeState;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\ApprovalWorkflow;
use Filament\Forms\Components\DatePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('stock opname navigation uses the plural qa label', function (): void {
    expect(StockOpnameResource::getNavigationLabel())->toBe('Stock Opnames')
        ->and(StockOpnameResource::getModelLabel())->toBe('Stock Opname')
        ->and(StockOpnameResource::getPluralModelLabel())->toBe('Stock Opnames');
});

test('stock opname item scope includes only active variants with warehouse activity', function (): void {
    $warehouse = Warehouse::factory()->create();
    $eligible = ProductVariant::factory()->create();
    $inactive = ProductVariant::factory()->create(['is_active' => false]);
    $zeroStock = ProductVariant::factory()->create();
    $empty = ProductVariant::factory()->create();

    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $eligible->id,
        'stock_available' => 5,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $inactive->id,
        'stock_available' => 5,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $zeroStock->id,
        'stock_available' => 0,
        'stock_reserved' => 0,
        'stock_in_transit' => 0,
        'stock_out' => 0,
    ]);
    StockAdjustment::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $empty->id,
    ]);

    $eligibleIds = ProductVariant::query()
        ->eligibleForStocktake($warehouse->id)
        ->pluck('id');

    expect($eligibleIds)
        ->toContain($eligible->id)
        ->toContain($zeroStock->id)
        ->not->toContain($inactive->id)
        ->not->toContain($empty->id);
});

test('stock opname scope includes legacy farming warehouse stock', function (): void {
    $warehouse = Warehouse::query()->where('code', 'WH-FARMING')->firstOrFail();
    $variant = ProductVariant::factory()->create(['stock_available' => 7]);

    expect(ProductVariant::query()
        ->eligibleForStocktake($warehouse->id)
        ->pluck('id'))
        ->toContain($variant->id);
});

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);
});

test('stock opname table displays session fields', function (): void {
    $warehouse = Warehouse::factory()->create();
    $opname = StockOpnameSession::factory()->create([
        'warehouse_id' => $warehouse->id,
    ]);

    Livewire::test(ListStockOpnames::class)
        ->assertCanSeeTableRecords([$opname])
        ->assertTableColumnExists('row_number')
        ->assertTableColumnExists('approval_status')
        ->assertTableColumnExists('opname_number')
        ->assertTableColumnExists('period')
        ->assertTableColumnExists('opname_date')
        ->assertTableColumnExists('warehouse.name')
        ->assertTableColumnExists('checker.name')
        ->assertTableColumnExists('witness.name')
        ->assertTableColumnExists('items_count')
        ->assertTableColumnExists('process_status')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('notes')
        ->assertTableColumnExists('createdBy.name')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updatedBy.name')
        ->assertTableColumnExists('updated_at');
});

test('stock opname list hides progress and log history columns', function (): void {
    $opname = StockOpnameSession::factory()->create();

    Livewire::test(ListStockOpnames::class)
        ->assertCanSeeTableRecords([$opname])
        ->assertTableColumnDoesNotExist('progress')
        ->assertTableColumnDoesNotExist('logs_count');
});

test('stock opname sessions rise to the top when they are updated', function (): void {
    $older = StockOpnameSession::factory()->create([
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinute(),
    ]);
    $newer = StockOpnameSession::factory()->create([
        'created_at' => now()->subMinute(),
        'updated_at' => now(),
    ]);

    Livewire::test(ListStockOpnames::class)
        ->assertCanSeeTableRecords([$newer, $older]);
});

test('stock opname draft has no number and saved numbers are sequential per day', function (): void {
    $draft = StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Draft]);
    $saved = StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Counting]);
    $nextSaved = StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Counting]);

    expect($draft->opname_number)->toBeNull()
        ->and($saved->opname_number)->toMatch('/^STO-\d{8}-001$/')
        ->and($nextSaved->opname_number)->toBe('STO-'.now()->format('Ymd').'-002');

    $draft->forceFill(['status' => StockOpnameStatus::Counting])->save();

    expect($draft->refresh()->opname_number)->toBe('STO-'.now()->format('Ymd').'-003');
});

test('existing stock opname items show their product and variant when edited', function (): void {
    $session = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Draft,
    ]);
    $variant = ProductVariant::factory()->create();
    StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $session->id,
        'product_variant_id' => $variant->id,
    ]);

    $page = Livewire::test(EditStockOpname::class, ['record' => $session->getRouteKey()]);
    $items = array_values($page->instance()->form->getRawState()['items']);

    expect($items[0]['item_label'])->toBe($variant->product->name.' - '.$variant->name);
});

test('saving a stock opname as draft shows pending approval status', function (): void {
    $warehouse = Warehouse::factory()->create();
    $checker = User::factory()->create();
    $witness = User::factory()->create();

    Livewire::test(CreateStockOpname::class)
        ->fillForm([
            'period' => now()->format('Y-m'),
            'opname_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'checker_id' => $checker->id,
            'witness_id' => $witness->id,
        ])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    $draft = StockOpnameSession::query()->latest('created_at')->firstOrFail();

    expect($draft->status)->toBe(StockOpnameStatus::Draft)
        ->and($draft->approval_status)->toBe(StockOpnameApprovalStatus::Pending);

    Livewire::test(ListStockOpnames::class)
        ->assertTableColumnStateSet('approval_status', 'Pending', $draft);

    $detail = Livewire::test(ViewStockOpname::class, ['record' => $draft->getRouteKey()]);
    $infolist = $detail->instance()->getSchema('infolist');

    expect($infolist->getComponent('approval_status')->getState())->toBe('Pending');
});

test('draft and revised stock opname sessions can be edited by their creator', function (): void {
    $draft = StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Draft]);
    $counting = StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Counting]);
    $revised = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Counting,
        'approval_status' => StockOpnameApprovalStatus::Revised,
    ]);

    expect(StockOpnameResource::canEdit($draft))->toBeTrue()
        ->and(StockOpnameResource::canEdit($counting))->toBeFalse()
        ->and(StockOpnameResource::canEdit($revised))->toBeTrue();

    Livewire::test(ListStockOpnames::class)
        ->assertTableActionExists('edit', record: $draft)
        ->assertTableActionHidden('edit', record: $counting)
        ->assertTableActionVisible('edit', record: $revised);
});

test('revised stock opname can be saved but cannot be saved as a draft', function (): void {
    $checker = User::factory()->create();
    $witness = User::factory()->create();
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockOpname->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);
    $revised = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Counting,
        'approval_status' => StockOpnameApprovalStatus::Revised,
        'approval_setting_id' => $setting->id,
        'checker_id' => $checker->id,
        'witness_id' => $witness->id,
    ]);

    Livewire::test(EditStockOpname::class, ['record' => $revised->getRouteKey()])
        ->assertSee('Save')
        ->assertDontSee('Save as Draft')
        ->fillForm([
            'period' => now()->format('Y-m'),
            'notes' => 'Updated after revision',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($revised->fresh()->status)->toBe(StockOpnameStatus::Counting)
        ->and($revised->fresh()->notes)->toBe('Updated after revision')
        ->and($revised->fresh()->approval_status)->toBe(StockOpnameApprovalStatus::Requested)
        ->and($revised->fresh()->approval_level)->toBe(1);
});

test('stock adjustment date field accepts a date without time', function (): void {
    $schema = Livewire::test(CreateStockAdjustment::class)->instance()->getSchema('form');

    expect($schema->getComponent('created_at'))->toBeInstanceOf(DatePicker::class);
});

test('stock opname approval uses the setting captured when the record is created', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockOpname->value)
        ->firstOrFail();
    $setting->update(['is_active' => false]);

    $draft = StockOpnameSession::factory()->create();

    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
    ]);

    $draft->forceFill(['status' => StockOpnameStatus::Counting])->save();
    $activeDraft = StockOpnameSession::factory()->create();
    $activeDraft->forceFill(['status' => StockOpnameStatus::Counting])->save();

    expect($draft->refresh()->approval_status)->toBe(StockOpnameApprovalStatus::Approved)
        ->and($draft->approval_setting_id)->toBeNull()
        ->and($activeDraft->refresh()->approval_status)->toBe(StockOpnameApprovalStatus::Requested)
        ->and($activeDraft->approval_setting_id)->toBe($setting->id);
});

test('final stock opname approval marks the session approved', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockOpname->value)
        ->firstOrFail();
    $role = Role::query()->where('name', 'super_admin')->firstOrFail();
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]);

    $opname = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Counting,
    ]);

    app(ApprovalWorkflow::class)->approve($opname, auth()->user());

    expect($opname->refresh()->approval_status)->toBe(StockOpnameApprovalStatus::Approved)
        ->and($opname->status)->toBe(StockOpnameStatus::Approved)
        ->and($opname->approved_at)->not->toBeNull()
        ->and($opname->approved_by)->toBe(auth()->id());
});

test('stock opname approval action follows the current level and requires unfreeze', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockOpname->value)
        ->firstOrFail();
    $role = Role::query()->where('name', 'super_admin')->firstOrFail();
    $secondRole = Role::query()->create([
        'name' => 'stock_opname_level_2',
        'code' => 'stock-opname-level-2',
        'guard_name' => 'web',
    ]);
    auth()->user()->assignRole($secondRole);

    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $secondRole->id,
    ]);

    $opname = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Counting,
    ]);

    Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
        ->assertActionHasLabel('approve', 'Approve Level 1')
        ->assertActionVisible('approve')
        ->assertActionVisible('rejectApproval')
        ->assertActionVisible('reviseApproval')
        ->callAction('approve')
        ->assertActionHasLabel('approve', 'Approve Level 2')
        ->assertSee('L1 Approved')
        ->assertSee('Approved Level 1');

    $opname->refresh();

    expect($opname->approval_status)->toBe(StockOpnameApprovalStatus::Pending)
        ->and($opname->approval_level)->toBe(2);

    Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
        ->assertActionHasLabel('approve', 'Approve Level 2')
        ->assertActionVisible('approve');

    Livewire::test(ListStockOpnames::class)
        ->assertTableColumnStateSet('approval_status', 'L1 Approved', $opname);

    $opname->freeze();

    Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('rejectApproval')
        ->assertActionHidden('reviseApproval');

    $opname->unfreeze();

    Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
        ->assertActionHasLabel('approve', 'Approve Level 2')
        ->assertActionVisible('approve');
});

test('only draft stock opname sessions can be soft deleted and restored', function (): void {
    $draft = StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Draft]);
    $counting = StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Counting]);

    expect(StockOpnameResource::canDelete($draft))->toBeTrue()
        ->and(StockOpnameResource::canDelete($counting))->toBeFalse();

    Livewire::test(ListStockOpnames::class)
        ->assertTableActionVisible('delete', record: $draft)
        ->assertTableActionHidden('delete', record: $counting);

    $draft->delete();

    $deletedDraft = StockOpnameSession::withTrashed()->findOrFail($draft->id);

    expect($deletedDraft->trashed())->toBeTrue()
        ->and($deletedDraft->deleted_by)->toBe(auth()->id());

    expect(StockOpnameResource::canRestore($deletedDraft))->toBeTrue();

    $deletedDraft->restore();

    expect($deletedDraft->refresh()->trashed())->toBeFalse()
        ->and($deletedDraft->deleted_by)->toBeNull();
});

test('stock opname list exposes freeze controls', function (): void {
    Livewire::test(ListStockOpnames::class)
        ->assertActionExists('freeze')
        ->assertActionExists('unfreeze');
});

test('stock opname create action stays enabled during system freeze', function (): void {
    $session = StockOpnameSession::factory()->create();
    SystemFreezeState::activate($session);

    Livewire::test(ListStockOpnames::class)
        ->assertActionVisible('create')
        ->assertActionEnabled('create')
        ->assertActionVisible('unfreeze');
});

test('stock opname create action is disabled when system is unfrozen', function (): void {
    Livewire::test(ListStockOpnames::class)
        ->assertActionVisible('create')
        ->assertActionDisabled('create')
        ->assertActionVisible('freeze');
});

test('stock opname create form contains the qa workflow fields', function (): void {
    Livewire::test(CreateStockOpname::class)
        ->assertFormFieldExists('opname_number')
        ->assertFormFieldExists('period')
        ->assertFormFieldExists('opname_date')
        ->assertFormFieldExists('warehouse_id')
        ->assertFormFieldExists('checker_id')
        ->assertFormFieldExists('witness_id')
        ->assertFormFieldExists('notes')
        ->assertFormFieldExists('items')
        ->assertSee('Save as Draft');
});

test('stock opname items table is shown once on view and not on edit', function (): void {
    $opname = StockOpnameSession::factory()->create();

    $editPage = Livewire::test(EditStockOpname::class, [
        'record' => $opname->getRouteKey(),
    ])->instance();
    $viewTest = Livewire::test(ViewStockOpname::class, [
        'record' => $opname->getRouteKey(),
    ]);
    $viewPage = $viewTest->instance();

    expect($editPage->getRelationManagers())->not->toContain(ItemsRelationManager::class)
        ->and($viewPage->getRelationManagers())->toContain(ItemsRelationManager::class)
        ->and($viewTest->instance()->getSchema('infolist')->getComponent('items_table'))->toBeNull();
});

test('stock opname period options stay open until every active location is approved', function (): void {
    $this->travelTo('2026-10-02 09:00:00');
    Warehouse::query()->update(['is_active' => false]);
    $firstWarehouse = Warehouse::factory()->create();
    $secondWarehouse = Warehouse::factory()->create();
    StockOpnameSession::factory()->create([
        'warehouse_id' => $firstWarehouse->id,
        'period' => '2026-10',
        'status' => StockOpnameStatus::Approved,
        'approval_status' => StockOpnameApprovalStatus::Approved,
    ]);

    $schema = Livewire::test(CreateStockOpname::class)->instance()->getSchema('form');
    $options = $schema->getComponent('period')->getOptions();

    expect($options)->toHaveKey('2026-10', 'October 2026');

    StockOpnameSession::factory()->create([
        'warehouse_id' => $secondWarehouse->id,
        'period' => '2026-10',
        'status' => StockOpnameStatus::Approved,
        'approval_status' => StockOpnameApprovalStatus::Approved,
    ]);
    $options = Livewire::test(CreateStockOpname::class)->instance()->getSchema('form')
        ->getComponent('period')->getOptions();
    $draftOctober = StockOpnameSession::factory()->create([
        'warehouse_id' => $firstWarehouse->id,
        'period' => '2026-10',
        'status' => StockOpnameStatus::Draft,
    ]);
    $editSchema = Livewire::test(EditStockOpname::class, [
        'record' => $draftOctober->getRouteKey(),
    ])->instance()->getSchema('form');

    expect($options)->not->toHaveKey('2026-09')
        ->and($options)->not->toHaveKey('2026-10')
        ->and($options)->toHaveKey('2026-11', 'November 2026');
    expect($editSchema->getComponent('period')->getOptions())->toHaveKey('2026-10', 'October 2026');
});

test('stock opname detail displays workflow information and logs', function (): void {
    $opname = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Counting,
        'approval_status' => StockOpnameApprovalStatus::Approved,
    ]);
    StockOpnameItem::factory()->create(['stock_opname_session_id' => $opname->id]);
    ApprovalLog::query()->create([
        'approvable_type' => StockOpnameSession::class,
        'approvable_id' => $opname->id,
        'module_code' => ApprovalModule::StockOpname->value,
        'level' => 1,
        'action' => 'approved',
        'acted_by' => auth()->id(),
    ]);

    Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
        ->assertSee('Progress')
        ->assertSee('Stock opname workflow progress')
        ->assertSee('Items')
        ->assertSee('Opname Information')
        ->assertSee('Detail Log')
        ->assertSee('Approved Level 1')
        ->assertSee('View Stock Opname')
        ->assertSee('Pending')
        ->assertSee('Opname Number')
        ->assertActionExists('approve')
        ->assertActionExists('rejectApproval')
        ->assertActionExists('reviseApproval');

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $opname,
        'pageClass' => ViewStockOpname::class,
    ])
        ->assertTableColumnExists('item')
        ->assertTableColumnExists('system_qty')
        ->assertTableColumnExists('physical_qty')
        ->assertTableColumnExists('variance_qty')
        ->assertTableColumnExists('unit')
        ->assertTableColumnExists('notes');
});

test('stock opname information shows finalization and process timestamps', function (): void {
    $opname = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Finalized,
        'approval_status' => StockOpnameApprovalStatus::Posted,
        'approved_at' => now()->subMinutes(4),
        'finalized_at' => now()->subMinutes(2),
    ]);

    Livewire::test(ViewStockOpname::class, ['record' => $opname->getRouteKey()])
        ->assertSee('Workflow Status')
        ->assertSee('Approval Status')
        ->assertSee('Finalized')
        ->assertSee('Yes')
        ->assertSee('Finalized At')
        ->assertSee('Start Time')
        ->assertSee('End Time')
        ->assertSee('Posted');
});

test('ended stock opname exposes the adjustment action only while variance remains', function (): void {
    $ended = StockOpnameSession::factory()->create([
        'approval_status' => StockOpnameApprovalStatus::Approved,
        'status' => StockOpnameStatus::Approved,
    ]);
    $pending = StockOpnameSession::factory()->create();
    $pending->forceFill(['approval_status' => StockOpnameApprovalStatus::Pending])->saveQuietly();

    $varianceItem = StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $ended->id,
        'variance_qty' => 2,
    ]);
    $balancedItem = StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $ended->id,
        'variance_qty' => 0,
    ]);
    $pendingItem = StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $pending->id,
        'variance_qty' => 2,
    ]);

    Livewire::test(ViewStockOpname::class, ['record' => $ended->id])
        ->assertActionVisible('createStockAdjustments');

    Livewire::test(ViewStockOpname::class, ['record' => $pending->id])
        ->assertActionHidden('createStockAdjustments');

    expect($varianceItem->stock_adjustment_id)->toBeNull()
        ->and($balancedItem->stock_adjustment_id)->toBeNull()
        ->and($pendingItem->stock_adjustment_id)->toBeNull();
});

test('ended stock opname stays ended until all adjustments post then finalizes as posted', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 10,
    ]);
    $opname = StockOpnameSession::factory()->create([
        'warehouse_id' => $warehouse->id,
        'approval_status' => StockOpnameApprovalStatus::Approved,
        'status' => StockOpnameStatus::Approved,
    ]);
    $item = StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $opname->id,
        'product_variant_id' => $variant->id,
        'system_qty' => 10,
        'physical_qty' => 7,
        'variance_qty' => -3,
    ]);
    $secondVariant = ProductVariant::factory()->create(['stock_available' => 2]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $secondVariant->id,
        'stock_available' => 2,
    ]);
    $secondItem = StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $opname->id,
        'product_variant_id' => $secondVariant->id,
        'system_qty' => 2,
        'physical_qty' => 5,
        'variance_qty' => 3,
    ]);
    $balancedItem = StockOpnameItem::factory()->create([
        'stock_opname_session_id' => $opname->id,
        'system_qty' => 4,
        'physical_qty' => 4,
        'variance_qty' => 0,
    ]);

    $url = StockAdjustmentResource::getUrl('create', [
        'source' => StockAdjustmentSource::StockOpname->value,
        'stock_opname_session_id' => $opname->id,
    ]);
    Livewire::test(ViewStockOpname::class, ['record' => $opname->id])
        ->callAction('createStockAdjustments')
        ->assertRedirect($url);

    Livewire::test(ListStockOpnames::class)
        ->assertTableColumnStateSet('process_status', 'Ended', $opname);

    Livewire::withQueryParams([
        'source' => StockAdjustmentSource::StockOpname->value,
        'stock_opname_session_id' => $opname->id,
    ])->test(CreateStockAdjustment::class)
        ->call('create')
        ->assertHasNoFormErrors();

    $item->refresh();
    $adjustment = StockAdjustment::query()->findOrFail($item->stock_adjustment_id);
    $secondItem->refresh();
    $balancedItem->refresh();

    expect($adjustment->type)->toBe(StockAdjustmentType::Kurang)
        ->and($adjustment->quantity)->toBe('3.000')
        ->and($adjustment->source)->toBe(StockAdjustmentSource::StockOpname)
        ->and($secondItem->stock_adjustment_id)->toBe($adjustment->id)
        ->and($balancedItem->stock_adjustment_id)->toBeNull()
        ->and(StockAdjustment::query()->count())->toBe(1)
        ->and($adjustment->warehouse_id)->toBe($warehouse->id)
        ->and(StockAdjustment::query()->where('adjustment_number', $adjustment->adjustment_number)->count())->toBe(1)
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('stock_available'))->toBe('10.000');

    $adjustmentItems = app(StockAdjustmentDetailPresenter::class)
        ->items($adjustment->fresh());
    expect($adjustmentItems)->toHaveCount(2)
        ->and(collect($adjustmentItems)->pluck('variance')->all())->toContain('-3', '3');

    $adjustmentSummary = $adjustment->fresh();
    expect($adjustmentSummary->product_summary)
        ->toContain($variant->product->name, $secondVariant->product->name)
        ->and($adjustmentSummary->quantity_summary)->toBe('2 items');

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->assertSee($variant->product->name)
        ->assertSee($secondVariant->product->name)
        ->assertSee('-3')
        ->assertSee('3');

    Livewire::test(ListStockAdjustments::class)
        ->assertTableColumnStateSet('quantity_summary', '2 items', $adjustment)
        ->filterTable('product_variant_id', $secondVariant->id)
        ->assertCanSeeTableRecords([$adjustment]);

    expect($opname->refresh()->status)->toBe(StockOpnameStatus::Approved)
        ->and($opname->approval_status)->toBe(StockOpnameApprovalStatus::Approved)
        ->and($opname->finalized_at)->toBeNull()
        ->and($opname->process_status)->toBe('Ended');

    Livewire::test(ListStockOpnames::class)
        ->assertTableColumnStateSet('process_status', 'Ended', $opname)
        ->assertTableColumnStateSet('approval_status', 'Approved', $opname);

    Livewire::test(ViewStockOpname::class, ['record' => $opname->id])
        ->assertActionHidden('createStockAdjustments')
        ->assertDontSee($adjustment->adjustment_number);

    $adjustment->applyToStock(auth()->id());

    expect($adjustment->refresh()->approval_status->value)->toBe('posted')
        ->and($opname->refresh()->approval_status)->toBe(StockOpnameApprovalStatus::Posted)
        ->and($opname->status)->toBe(StockOpnameStatus::Finalized)
        ->and($opname->finalized_at)->not->toBeNull()
        ->and($opname->process_status)->toBe('Finalized')
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('stock_available'))->toBe('7.000')
        ->and(WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $secondVariant->id)
            ->value('stock_available'))->toBe('5.000')
        ->and($variant->refresh()->stock_available)->toBe('7.000')
        ->and($secondVariant->refresh()->stock_available)->toBe('5.000')
        ->and($adjustment->stockMutations()->count())->toBe(2)
        ->and($opname->logs()->where('to_status', StockOpnameStatus::Finalized->value)->exists())->toBeTrue();

    Livewire::test(ListStockOpnames::class)
        ->assertTableColumnStateSet('process_status', 'Finalized', $opname)
        ->assertTableColumnStateSet('approval_status', 'Posted', $opname);

    Livewire::test(ViewStockOpname::class, ['record' => $opname->id])
        ->assertActionHidden('createStockAdjustments');
});

test('creating a stock opname session stores the active counting status', function (): void {
    $warehouse = Warehouse::factory()->create();
    $checker = User::factory()->create();
    $witness = User::factory()->create();

    $component = Livewire::test(CreateStockOpname::class)
        ->fillForm([
            'period' => now()->format('Y-m'),
            'opname_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'checker_id' => $checker->id,
            'witness_id' => $witness->id,
            'items' => [[
                'product_variant_id' => ProductVariant::factory()->create()->id,
                'system_qty' => 0,
                'physical_qty' => 0,
                'variance_qty' => 0,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $opname = StockOpnameSession::query()->where('warehouse_id', $warehouse->id)->latest()->firstOrFail();

    $component->assertRedirect(StockOpnameResource::getUrl('view', ['record' => $opname]));

    expect($opname->status)->toBe(StockOpnameStatus::Counting);
});

test('stock opname requires different checker and witness users', function (): void {
    $warehouse = Warehouse::factory()->create();
    $checker = User::factory()->create();
    $variant = ProductVariant::factory()->create();

    Livewire::test(CreateStockOpname::class)
        ->fillForm([
            'period' => now()->format('Y-m'),
            'opname_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'checker_id' => $checker->id,
            'witness_id' => $checker->id,
            'items' => [[
                'product_variant_id' => $variant->id,
                'system_qty' => 0,
                'physical_qty' => 0,
                'variance_qty' => 0,
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors(['witness_id']);

    expect(StockOpnameSession::query()->count())->toBe(0);
});
