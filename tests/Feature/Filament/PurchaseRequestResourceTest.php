<?php

use App\Enums\ApprovalModule;
use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ManagePurchaseRequests;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Item;
use App\Models\Permission;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\ApprovalRequestInbox;
use App\Services\ApprovalWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

test('purchase requests use lifecycle status and generate a request number when saved', function (): void {
    $record = PurchaseRequest::factory()->create();

    expect($record->id)->toBeString()
        ->and($record->pr_number)->toStartWith('PR-')
        ->and($record->pr_number)->toMatch('/^PR-\d{8}-\d{3}$/')
        ->and($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->approval_status)->toBe(PurchaseRequestApprovalStatus::Approved)
        ->and($record->markAsDraft())->toBeTrue()
        ->and($record->fresh()->record_status)->toBe(RecordStatus::Draft);
});

test('purchase request create form has a dedicated page route', function (): void {
    $this->actor->forceFill(['sso_id' => (string) Str::uuid()])->save();

    $this->get(PurchaseRequestResource::getUrl('create'))->assertOk();
});

test('purchase request draft skips approval and enters it when saved', function (): void {
    $setting = purchaseRequestApprovalSetting(1);
    $draft = PurchaseRequest::factory()->create(['record_status' => RecordStatus::Draft]);

    expect($draft->pr_number)->toBeNull()
        ->and($draft->approval_status)->toBe(PurchaseRequestApprovalStatus::Pending)
        ->and($draft->approvalProgressLabel())->toBe('Pending')
        ->and($draft->approval_level)->toBe(0)
        ->and($draft->approval_setting_id)->toBeNull()
        ->and($draft->approvalLogs()->exists())->toBeFalse();

    $draft->markAsSaved();

    expect($draft->fresh()->pr_number)->toStartWith('PR-')
        ->and($draft->fresh()->approval_status)->toBe(PurchaseRequestApprovalStatus::Requested)
        ->and($draft->fresh()->approval_level)->toBe(1)
        ->and($draft->fresh()->approval_setting_id)->toBe($setting->id)
        ->and($draft->approvalLogs()->where('action', 'requested')->exists())->toBeTrue();
});

test('one approval level approves a request after its level one decision', function (): void {
    purchaseRequestApprovalSetting(1);
    $request = PurchaseRequest::factory()->create();

    expect($request->approval_status)->toBe(PurchaseRequestApprovalStatus::Requested);

    app(ApprovalWorkflow::class)->approve($request, $this->actor);

    expect($request->fresh()->approval_status)->toBe(PurchaseRequestApprovalStatus::Approved)
        ->and($request->fresh()->approval_level)->toBe(2)
        ->and($request->approvalLogs()->where('action', 'approved')->count())->toBe(1);
});

test('two approval levels require level one and level two before approval', function (): void {
    purchaseRequestApprovalSetting(2);
    $request = PurchaseRequest::factory()->create();
    $workflow = app(ApprovalWorkflow::class);

    $workflow->approve($request, $this->actor);
    $afterLevelOne = $request->fresh();

    expect($afterLevelOne->approval_status)->toBe(PurchaseRequestApprovalStatus::Pending)
        ->and($afterLevelOne->approval_level)->toBe(2)
        ->and($afterLevelOne->approvalProgressLabel())->toBe('L1 Approved');

    $workflow->approve($afterLevelOne, $this->actor);

    expect($request->fresh()->approval_status)->toBe(PurchaseRequestApprovalStatus::Approved)
        ->and($request->fresh()->approval_level)->toBe(3)
        ->and($request->approvalLogs()->where('action', 'approved')->count())->toBe(2);
});

test('purchase request detail shows each completed approval level before final approval', function (): void {
    purchaseRequestApprovalSetting(3);
    $request = PurchaseRequest::factory()->create();

    app(ApprovalWorkflow::class)->approve($request, $this->actor);

    Livewire::test(ViewPurchaseRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('L1 Approved');

    app(ApprovalWorkflow::class)->approve($request->fresh(), $this->actor);

    Livewire::test(ViewPurchaseRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('L2 Approved');

    app(ApprovalWorkflow::class)->approve($request->fresh(), $this->actor);

    Livewire::test(ViewPurchaseRequest::class, ['record' => $request->getRouteKey()])
        ->assertSee('Approved');
});

test('purchase request lines retain item, ordered quantity, specification, and notes', function (): void {
    $request = PurchaseRequest::factory()->create();
    $item = Item::factory()->create();

    $line = PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $request->id,
        'item_id' => $item->id,
        'qty_order' => 12.5,
        'specification' => 'Grade A',
        'notes' => 'Deliver fresh',
    ]);

    expect($request->items()->first()->id)->toBe($line->id)
        ->and($line->item->id)->toBe($item->id)
        ->and($line->qty_order)->toBe('12.500')
        ->and($line->specification)->toBe('Grade A')
        ->and($line->notes)->toBe('Deliver fresh');
});

test('creating a purchase request saves its checklist and starts approval', function (): void {
    purchaseRequestApprovalSetting(2);
    $warehouse = Warehouse::factory()->create();
    $item = Item::factory()->create();

    Livewire::test(CreatePurchaseRequest::class)
        ->fillForm([
            'pr_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'requested_by' => $this->actor->id,
            'required_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Monthly supply order',
            'items' => [[
                'item_id' => $item->id,
                'selected' => true,
                'qty_order' => 8,
                'specification' => 'Large size',
                'notes' => 'Deliver in the morning',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = PurchaseRequest::query()->with('items')->sole();

    expect($request->record_status)->toBe(RecordStatus::Saved)
        ->and($request->approval_status)->toBe(PurchaseRequestApprovalStatus::Requested)
        ->and($request->approval_level)->toBe(1)
        ->and($request->items)->toHaveCount(1)
        ->and($request->items->first()->item_id)->toBe($item->id)
        ->and($request->items->first()->qty_order)->toBe('8.000')
        ->and($request->approvalLogs()->where('action', 'requested')->exists())->toBeTrue();
});

test('purchase request create page can save a checked checklist as draft', function (): void {
    $warehouse = Warehouse::factory()->create();
    $item = Item::factory()->create();

    Livewire::test(CreatePurchaseRequest::class)
        ->fillForm([
            'pr_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'requested_by' => $this->actor->id,
            'required_date' => now()->addDays(7)->toDateString(),
            'items' => [[
                'item_id' => $item->id,
                'selected' => true,
                'qty_order' => 3,
            ]],
        ])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    $request = PurchaseRequest::query()->with('items')->sole();

    expect($request->record_status)->toBe(RecordStatus::Draft)
        ->and($request->approval_status)->toBe(PurchaseRequestApprovalStatus::Pending)
        ->and($request->approvalLogs()->exists())->toBeFalse()
        ->and($request->items)->toHaveCount(1);
});

test('checking a purchase request item initializes its order quantity', function (): void {
    $item = Item::factory()->create();

    Livewire::test(CreatePurchaseRequest::class)
        ->fillForm([
            'items' => [[
                'item_id' => $item->id,
                'selected' => false,
                'qty_order' => null,
            ]],
        ])
        ->set('data.items.0.selected', true)
        ->assertSet('data.items.0.qty_order', '1.000');
});

test('purchase request checklist filters items by the selected stock category', function (): void {
    purchaseRequestApprovalSetting(1);
    $warehouse = Warehouse::factory()->create();
    $item = Item::factory()->create([
        'initial_stock' => 25,
        'min_stock' => 5,
    ]);
    $uncheckedItem = Item::factory()->create([
        'initial_stock' => 10,
        'min_stock' => 5,
    ]);

    Livewire::test(CreatePurchaseRequest::class)
        ->fillForm([
            'pr_date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'requested_by' => $this->actor->id,
            'required_date' => now()->addDays(7)->toDateString(),
            'item_category' => 'not_critical',
            'items' => [[
                'item_id' => $item->id,
                'selected' => true,
                'qty_order' => 4,
            ], [
                'item_id' => $uncheckedItem->id,
                'selected' => false,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PurchaseRequestItem::query()->sole()->item_id)->toBe($item->id);
});

test('purchase request checklist status matches the selected critical category', function (): void {
    $warehouse = Warehouse::factory()->create();
    $criticalItem = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'initial_stock' => 10,
        'min_stock' => 10,
    ]);
    $notCriticalItem = Item::factory()->create([
        'initial_warehouse_id' => $warehouse->id,
        'initial_stock' => 20,
        'min_stock' => 10,
    ]);

    Livewire::test(CreatePurchaseRequest::class)
        ->set('data.warehouse_id', $warehouse->id)
        ->set('data.item_category', 'critical')
        ->assertSet('data.items.0.item_id', $criticalItem->id)
        ->assertSet('data.items.0.status', 'Critical')
        ->set('data.item_category', 'not_critical')
        ->assertSet('data.items.0.item_id', $notCriticalItem->id)
        ->assertSet('data.items.0.status', 'OK');
});

test('editing a purchase request persists only checked checklist rows', function (): void {
    $request = PurchaseRequest::factory()->create(['record_status' => RecordStatus::Draft]);
    $selectedItem = Item::factory()->create();
    $uncheckedItem = Item::factory()->create();
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $request->id,
        'item_id' => $selectedItem->id,
        'qty_order' => 2,
    ]);

    Livewire::test(ManagePurchaseRequests::class)
        ->callTableAction('edit', $request, data: [
            'pr_date' => now()->toDateString(),
            'warehouse_id' => $request->warehouse_id,
            'requested_by' => $this->actor->id,
            'required_date' => now()->addDays(7)->toDateString(),
            'items' => [[
                'item_id' => $selectedItem->id,
                'selected' => true,
                'qty_order' => 9,
            ], [
                'item_id' => $uncheckedItem->id,
                'selected' => false,
            ]],
        ])
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->items)->toHaveCount(1)
        ->and($request->fresh()->items->first()->item_id)->toBe($selectedItem->id)
        ->and($request->fresh()->items->first()->qty_order)->toBe('9.000');
});

test('editing a draft purchase request fills its saved checklist rows', function (): void {
    $request = PurchaseRequest::factory()->create(['record_status' => RecordStatus::Draft]);
    $item = Item::factory()->create();
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $request->id,
        'item_id' => $item->id,
        'qty_order' => 12,
        'specification' => 'Grade A',
        'notes' => 'Deliver fresh',
    ]);

    Livewire::test(ManagePurchaseRequests::class)
        ->mountTableAction('edit', $request)
        ->assertTableActionDataSet(function (array $data) use ($item): array {
            expect(collect($data['items'] ?? [])->contains(fn (array $line): bool => ($line['item_id'] ?? null) === $item->id
                && ($line['qty_order'] ?? null) == '12.000'
                && ($line['specification'] ?? null) === 'Grade A'
                && ($line['notes'] ?? null) === 'Deliver fresh'))->toBeTrue();

            return [];
        });
});

test('purchase request clone uses the create and save draft flow with editable copied lines', function (): void {
    $request = PurchaseRequest::factory()->create([
        'record_status' => RecordStatus::Saved,
        'approval_status' => PurchaseRequestApprovalStatus::Approved,
    ]);
    $item = Item::factory()->create();
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $request->id,
        'item_id' => $item->id,
        'qty_order' => 5,
        'specification' => 'Original spec',
        'notes' => 'Original note',
    ]);

    $page = Livewire::test(ManagePurchaseRequests::class)
        ->mountTableAction('clone', $request)
        ->assertTableActionDataSet(function (array $data) use ($item): array {
            expect(collect($data['items'] ?? [])->contains(fn (array $line): bool => ($line['item_id'] ?? null) === $item->id))->toBeTrue();

            return [];
        });

    $data = [
        'pr_date' => $request->pr_date->toDateString(),
        'warehouse_id' => $request->warehouse_id,
        'requested_by' => $request->requested_by,
        'required_date' => $request->required_date->toDateString(),
        'item_category' => 'critical',
        'item_search' => '',
        'items' => [[
            'item_id' => $item->id,
            'selected' => true,
            'item_label' => $item->code.' - '.$item->name,
            'unit' => $item->unit?->code ?? '-',
            'available' => '0.000',
            'critical' => '0.000',
            'status' => 'Critical',
            'qty_order' => '9.000',
            'specification' => 'Updated spec',
            'notes' => 'Updated note',
        ]],
    ];

    $page->setTableActionData($data)
        ->callMountedTableAction(arguments: ['draft' => true])
        ->assertHasNoTableActionErrors();

    $replica = PurchaseRequest::query()->whereKeyNot($request->id)->sole();

    expect($replica->record_status)->toBe(RecordStatus::Draft)
        ->and($replica->approval_status)->toBe(PurchaseRequestApprovalStatus::Pending)
        ->and($replica->items)->toHaveCount(1)
        ->and($replica->items->first()->qty_order)->toBe('9.000')
        ->and($replica->items->first()->specification)->toBe('Updated spec')
        ->and($replica->items->first()->notes)->toBe('Updated note');
});

test('purchase request item critical classification uses the selected location stock', function (): void {
    $initialLocation = Warehouse::factory()->create();
    $requestedLocation = Warehouse::factory()->create();
    $item = Item::factory()->create([
        'initial_warehouse_id' => $initialLocation->id,
        'initial_stock' => 20,
        'min_stock' => 10,
    ]);

    Livewire::test(CreatePurchaseRequest::class)
        ->assertSet('data.items', [])
        ->set('data.item_category', 'critical')
        ->set('data.warehouse_id', $requestedLocation->id)
        ->assertSet('data.items.0.item_id', $item->id)
        ->assertSet('data.items.0.selected', false)
        ->assertSet('data.items.0.available', '0.00')
        ->assertSet('data.items.0.critical', '10.00')
        ->assertSet('data.items.0.status', 'Critical');
});

test('purchase request detail exposes approval actions to an assigned approver', function (): void {
    purchaseRequestApprovalSetting(1);
    $request = PurchaseRequest::factory()->create();

    Livewire::test(ViewPurchaseRequest::class, ['record' => $request->getRouteKey()])
        ->assertOk()
        ->assertActionVisible('approve')
        ->assertActionVisible('reviseApproval')
        ->assertActionVisible('rejectApproval');
});

test('approvers receive a notification and see purchase requests in the approval inbox', function (): void {
    Notification::fake();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $approverRole = Role::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole($approverRole);
    $approverRole->givePermissionTo(Permission::findOrCreate('ViewAny:PurchaseRequest', 'web'));
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::PurchaseRequest->value)
        ->firstOrFail();
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $approverRole->id,
    ]);
    $this->actingAs($approver);

    $request = PurchaseRequest::factory()->create(['requested_by' => $approver->id]);

    Notification::assertSentTo($approver, ApprovalRequestedNotification::class);

    $inbox = app(ApprovalRequestInbox::class)->paginate($approver, 1, 25);

    expect($inbox->total())->toBe(1)
        ->and($inbox->items()[0]['number'])->toBe($request->pr_number)
        ->and($inbox->items()[0]['type'])->toBe('Purchase Request');
});

test('purchase request table exposes the requested columns', function (): void {
    $page = Livewire::test(ManagePurchaseRequests::class)
        ->assertTableColumnExists('row_number')
        ->assertTableColumnExists('approval_status')
        ->assertTableColumnExists('pr_number')
        ->assertTableColumnExists('pr_date')
        ->assertTableColumnExists('warehouse.name')
        ->assertTableColumnExists('requestedBy.name')
        ->assertTableColumnExists('required_date')
        ->assertTableColumnExists('record_status')
        ->assertTableColumnExists('approval_logs_count')
        ->assertTableColumnExists('notes')
        ->assertTableColumnExists('createdBy.name')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updatedBy.name')
        ->assertTableColumnExists('updated_at')
        ->assertTableColumnExists('deletedBy.name')
        ->assertTableColumnExists('deleted_at');

    $columns = array_keys($page->instance()->getTable()->getColumns());

    expect($page->instance()->getTable()->getRecordActionsColumnLabel())->toBe('Action')
        ->and(array_search('created_at', $columns, true))->toBeLessThan(array_search('createdBy.name', $columns, true))
        ->and(array_search('updated_at', $columns, true))->toBeLessThan(array_search('updatedBy.name', $columns, true))
        ->and(array_search('deleted_at', $columns, true))->toBeLessThan(array_search('deletedBy.name', $columns, true));
});

function purchaseRequestApprovalSetting(int $levelCount): ApprovalSetting
{
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::PurchaseRequest->value)
        ->firstOrFail();

    foreach (range(1, $levelCount) as $level) {
        ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);
    }

    return $setting;
}
