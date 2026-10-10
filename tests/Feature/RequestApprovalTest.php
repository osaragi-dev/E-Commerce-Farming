<?php

use App\Enums\ApprovalModule;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Enums\StockAdjustmentApprovalStatus;
use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\RequestApproval;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Services\ApprovalRequestInbox;
use App\Services\PaymentVoidWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;

function grantApprovalInboxModule(User $user, string $permission): void
{
    $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
}

function approvalSettingFor(ApprovalModule $module): ApprovalSetting
{
    return ApprovalSetting::query()->where('module_code', $module->value)->firstOrFail();
}

function assignRoleToApprovalModule(Role $role, ApprovalModule $module, int $level = 1): ApprovalSettingLevel
{
    $setting = approvalSettingFor($module);
    $setting->update(['is_active' => true]);

    return ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
        'level' => $level,
    ]);
}

test('request approval follows dashboard outside the Operations group', function (): void {
    expect(RequestApproval::getNavigationGroup())->toBeNull()
        ->and(RequestApproval::getNavigationSort())->toBe(Dashboard::getNavigationSort() + 1);
});

test('request approval lists permitted history for all configured modules with resource review links', function (): void {
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $role = Role::factory()->create(['name' => 'approval_reviewer', 'code' => 'approval_reviewer']);
    $reviewer->assignRole($role);

    foreach ([
        'ViewAny:Payment',
        'ViewAny:StockAdjustment',
        'ViewAny:StockMovement',
        'ViewAny:StockOpnameSession',
    ] as $permission) {
        grantApprovalInboxModule($reviewer, $permission);
    }

    foreach (ApprovalModule::cases() as $module) {
        assignRoleToApprovalModule($role, $module);
    }

    $adjustment = StockAdjustment::factory()->create([
        'record_status' => RecordStatus::Saved,
        'adjusted_by' => $requester->id,
        'note' => 'Adjustment details',
    ]);

    $movement = StockMovement::factory()->create([
        'requested_by' => $requester->id,
        'notes' => 'Movement details',
    ]);

    $opname = StockOpnameSession::factory()->create([
        'status' => StockOpnameStatus::Counting,
        'created_by' => $requester->id,
        'notes' => 'Opname details',
    ]);

    $order = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Paid,
    ]);
    grantApprovalInboxModule($requester, 'Void:Payment');
    $voidRequest = app(PaymentVoidWorkflow::class)->request($payment, $requester, 'Payment details');

    $this->actingAs($reviewer);

    Livewire::test(RequestApproval::class)
        ->assertTableColumnExists('type')
        ->assertTableColumnExists('number')
        ->assertTableColumnExists('date')
        ->assertTableColumnExists('requested_by')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('progress')
        ->assertTableColumnExists('requested_at')
        ->assertTableColumnExists('updated_at')
        ->assertTableColumnExists('notes')
        ->assertSee($adjustment->adjustment_number)
        ->assertSee($movement->movement_number)
        ->assertSee($opname->opname_number)
        ->assertSee($payment->payment_number)
        ->assertSee('Review');

    $rows = app(ApprovalRequestInbox::class)->paginate($reviewer, 1, 50)->getCollection()->keyBy('type');

    expect($rows->keys()->all())->toContain(
        'Void Payment',
        'Stock Adjustment',
        'Stock Movement',
        'Stock Opname',
    )
        ->and($rows)->toHaveCount(4)
        ->and($rows['Void Payment']['review_url'])->toBe(PaymentResource::getUrl('view', ['record' => $payment]))
        ->and($rows['Stock Adjustment']['review_url'])->toBe(StockAdjustmentResource::getUrl('view', ['record' => $adjustment]))
        ->and($rows['Stock Movement']['review_url'])->toBe(StockMovementResource::getUrl('view', ['record' => $movement]))
        ->and($rows['Stock Opname']['review_url'])->toBe(StockOpnameResource::getUrl('view', ['record' => $opname]));
});

test('request approval requires module visibility as well as a role on an approval level', function (): void {
    $reviewer = User::factory()->create();
    $role = Role::factory()->create(['name' => 'adjustment_reviewer', 'code' => 'adjustment_reviewer']);
    $reviewer->assignRole($role);
    assignRoleToApprovalModule($role, ApprovalModule::StockAdjustment);
    assignRoleToApprovalModule($role, ApprovalModule::StockMovement);
    grantApprovalInboxModule($reviewer, 'ViewAny:StockAdjustment');

    $adjustment = StockAdjustment::factory()->create(['record_status' => RecordStatus::Saved]);
    $movement = StockMovement::factory()->create();

    expect($adjustment->approval_setting_id)->toBe(approvalSettingFor(ApprovalModule::StockAdjustment)->id)
        ->and(app(ApprovalRequestInbox::class)->canReviewDetail($reviewer, $adjustment))->toBeTrue();

    $this->actingAs($reviewer);

    expect(RequestApproval::canAccess())->toBeTrue();

    Livewire::test(RequestApproval::class)
        ->assertSee($adjustment->adjustment_number)
        ->assertDontSee($movement->movement_number);

    $withoutModulePermission = User::factory()->create();
    $withoutModulePermission->assignRole($role);
    $this->actingAs($withoutModulePermission);

    expect(app(ApprovalRequestInbox::class)->canAccess($withoutModulePermission))->toBeFalse()
        ->and(app(ApprovalRequestInbox::class)->paginate($withoutModulePermission, 1, 10)->total())->toBe(0)
        ->and(RequestApproval::canAccess())->toBeFalse();
});

test('request and resubmit events preserve the latest requested at timestamp', function (): void {
    $requester = User::factory()->create();
    $role = Role::factory()->create(['name' => 'adjustment_reviewer', 'code' => 'adjustment_reviewer']);
    $requester->assignRole($role);
    assignRoleToApprovalModule($role, ApprovalModule::StockAdjustment);
    grantApprovalInboxModule($requester, 'ViewAny:StockAdjustment');
    $this->actingAs($requester);

    $this->travelTo(Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta'));
    $adjustment = StockAdjustment::factory()->create(['record_status' => RecordStatus::Saved]);
    $firstRequestAt = $adjustment->approvalLogs()->where('action', 'requested')->sole()->created_at;

    $adjustment->forceFill(['approval_status' => StockAdjustmentApprovalStatus::Revised])->save();
    $this->travel(5)->minutes();
    $adjustment->update(['note' => 'Corrected adjustment']);

    $rows = app(ApprovalRequestInbox::class)->paginate($requester, 1, 10)->getCollection();

    expect($adjustment->refresh()->approval_status)->toBe(StockAdjustmentApprovalStatus::Requested)
        ->and($adjustment->approvalLogs()->where('action', 'requested')->count())->toBe(2)
        ->and($rows->sole()['requested_at']->greaterThan($firstRequestAt))->toBeTrue();
});

test('final approved stock movement requests are hidden from the inbox', function (): void {
    $reviewer = User::factory()->create();
    $role = Role::factory()->create(['name' => 'movement_reviewer', 'code' => 'movement_reviewer']);
    $reviewer->assignRole($role);
    assignRoleToApprovalModule($role, ApprovalModule::StockMovement);
    assignRoleToApprovalModule($role, ApprovalModule::StockAdjustment);
    grantApprovalInboxModule($reviewer, 'ViewAny:StockMovement');
    grantApprovalInboxModule($reviewer, 'ViewAny:StockAdjustment');

    $movement = StockMovement::factory()->create();
    $movement->forceFill([
        'approval_status' => StockMovementApprovalStatus::Approved,
        'approval_level' => 2,
        'status' => StockMovementStatus::Shipped,
    ])->save();

    $adjustment = StockAdjustment::factory()->create([
        'record_status' => RecordStatus::Saved,
    ]);
    $adjustment->forceFill(['approval_status' => StockAdjustmentApprovalStatus::Posted])->save();

    expect(app(ApprovalRequestInbox::class)->paginate($reviewer, 1, 10)->total())->toBe(0);
});

test('request approval sorts every data column in both directions', function (): void {
    $reviewer = User::factory()->create();
    $role = Role::factory()->create(['name' => 'sort_reviewer', 'code' => 'sort_reviewer']);
    $reviewer->assignRole($role);
    $firstLevel = assignRoleToApprovalModule($role, ApprovalModule::StockAdjustment);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $firstLevel->approval_setting_id,
        'role_id' => Role::factory()->create(['name' => 'sort_level_two', 'code' => 'sort_level_two'])->id,
        'level' => 2,
    ]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $firstLevel->approval_setting_id,
        'role_id' => Role::factory()->create(['name' => 'sort_level_three', 'code' => 'sort_level_three'])->id,
        'level' => 3,
    ]);
    grantApprovalInboxModule($reviewer, 'ViewAny:StockAdjustment');

    foreach (['Alpha', 'Delta', 'Charlie', 'Bravo'] as $index => $name) {
        $requester = User::factory()->create(['name' => $name]);
        $adjustment = StockAdjustment::factory()->create([
            'record_status' => RecordStatus::Saved,
            'adjusted_by' => $requester->id,
            'note' => 'Notes '.$name,
        ]);
        $requestedAt = Carbon::parse('2026-10-01 10:00:00', 'Asia/Jakarta')->addDays($index);
        $adjustment->forceFill([
            'approval_status' => $index === 1 ? StockAdjustmentApprovalStatus::Revised : StockAdjustmentApprovalStatus::Pending,
            'approval_level' => $index + 1,
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt->copy()->addMinutes($index),
        ])->saveQuietly();
        $adjustment->approvalLogs()->where('action', 'requested')->update([
            'created_at' => $requestedAt,
            'updated_at' => $requestedAt,
        ]);

        for ($level = 1; $level <= $index; $level++) {
            $adjustment->approvalLogs()->create([
                'module_code' => ApprovalModule::StockAdjustment->value,
                'level' => $level,
                'action' => 'approved',
                'acted_by' => $reviewer->id,
                'created_at' => $requestedAt->copy()->addMinutes($level),
            ]);
        }
    }

    $columns = [
        'type',
        'number',
        'date',
        'requested_by',
        'status',
        'progress',
        'requested_at',
        'updated_at',
        'notes',
    ];

    foreach ($columns as $column) {
        $ascending = app(ApprovalRequestInbox::class)
            ->paginate($reviewer, 1, 'all', sortColumn: $column, sortDirection: 'asc')
            ->getCollection()
            ->pluck('key')
            ->all();
        $descending = app(ApprovalRequestInbox::class)
            ->paginate($reviewer, 1, 'all', sortColumn: $column, sortDirection: 'desc')
            ->getCollection()
            ->pluck('key')
            ->reverse()
            ->values()
            ->all();

        expect($descending)->toBe($ascending);
    }

    $this->actingAs($reviewer);
    $component = Livewire::test(RequestApproval::class);

    foreach ($columns as $column) {
        $component->assertTableColumnExists($column);
        expect($component->instance()->getTable()->getColumn($column)->isSortable())->toBeTrue();
    }

    $component->sortTable('requested_by', 'asc');
    expect($component->instance()->getTableRecords()->getCollection()->pluck('requested_by')->all())
        ->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta']);

    $component->sortTable('requested_by', 'desc');
    expect($component->instance()->getTableRecords()->getCollection()->pluck('requested_by')->all())
        ->toBe(['Delta', 'Charlie', 'Bravo', 'Alpha']);
});

test('request approval pagination follows the Filament table page state', function (): void {
    $reviewer = User::factory()->create();
    $role = Role::factory()->create(['name' => 'pagination_reviewer', 'code' => 'pagination_reviewer']);
    $reviewer->assignRole($role);
    assignRoleToApprovalModule($role, ApprovalModule::StockAdjustment);
    grantApprovalInboxModule($reviewer, 'ViewAny:StockAdjustment');

    $adjustments = StockAdjustment::factory()->count(12)->create(['record_status' => RecordStatus::Saved]);
    $this->actingAs($reviewer);

    $allNumbers = app(ApprovalRequestInbox::class)
        ->paginate($reviewer, 1, 'all', sortColumn: 'number', sortDirection: 'asc')
        ->getCollection()
        ->pluck('number');

    $component = Livewire::test(RequestApproval::class)
        ->assertSeeHtml('<option value="all">')
        ->set('tableRecordsPerPage', 10)
        ->sortTable('number', 'asc')
        ->assertSee($allNumbers[0])
        ->assertDontSee($allNumbers[11])
        ->call('setPage', 2, 'page')
        ->assertSee($allNumbers[11])
        ->assertDontSee($allNumbers[0]);

    expect($component->instance()->getTableRecords()->getPageName())->toBe('page')
        ->and($component->instance()->getTable()->getPaginationPageOptions())->toBe([10, 25, 50, 100, 'all']);

    $component
        ->set('tableRecordsPerPage', 'all')
        ->assertSee($allNumbers[0])
        ->assertSee($allNumbers[11]);

    expect($component->instance()->getTableRecords()->getCollection()->count())->toBe(12);

    $component
        ->set('tableSearch', $allNumbers[0])
        ->assertSee($allNumbers[0])
        ->assertDontSee($allNumbers[11]);

    expect($component->instance()->getTableRecords()->total())->toBe(1);
});

test('request approval exports only rows with per-module export permission', function (): void {
    $reviewer = User::factory()->create();
    $role = Role::factory()->create(['name' => 'export_reviewer', 'code' => 'export_reviewer']);
    $reviewer->assignRole($role);
    assignRoleToApprovalModule($role, ApprovalModule::StockAdjustment);
    assignRoleToApprovalModule($role, ApprovalModule::StockMovement);
    grantApprovalInboxModule($reviewer, 'ViewAny:StockAdjustment');
    grantApprovalInboxModule($reviewer, 'ViewAny:StockMovement');
    grantApprovalInboxModule($reviewer, 'Export:StockAdjustment');

    $adjustment = StockAdjustment::factory()->create(['record_status' => RecordStatus::Saved]);
    $movement = StockMovement::factory()->create();
    $inbox = app(ApprovalRequestInbox::class);
    $rows = $inbox->paginate($reviewer, 1, 50)->getCollection();

    expect($inbox->canExportAnyModule($reviewer))->toBeTrue()
        ->and($inbox->exportableRows($reviewer, $rows)->pluck('number')->all())
        ->toBe([$adjustment->adjustment_number]);

    $exportCalls = [];
    $response = response()->streamDownload(static fn (): int => print 'ok', 'request-approval.csv');
    $exportService = Mockery::mock(TableExportService::class);
    $exportService->shouldReceive('downloadCsv')
        ->twice()
        ->withArgs(function (Collection $exportedRows, string $filenameSource, TableExportDefinition $definition) use (&$exportCalls, $adjustment): bool {
            $exportCalls[] = $exportedRows->pluck('number')->all();

            return $filenameSource === RequestApproval::class
                && $exportedRows->pluck('number')->all() === [$adjustment->adjustment_number]
                && ! array_key_exists('review_url', $definition->columns);
        })
        ->andReturn($response);
    app()->instance(TableExportService::class, $exportService);

    $this->actingAs($reviewer);

    $component = Livewire::test(RequestApproval::class)
        ->assertTableActionExists('exportCsv')
        ->assertTableBulkActionExists('exportSelectedCsv');
    $selectedKeys = $component->instance()->getTableRecords()->getCollection()->keys()->all();

    $component
        ->callTableAction('exportCsv')
        ->call('mountTableBulkAction', 'exportSelectedCsv', $selectedKeys)
        ->callMountedTableBulkAction()
        ->assertHasNoActionErrors();

    expect($exportCalls)->toHaveCount(2);

    $reviewerWithoutExport = User::factory()->create();
    $reviewerWithoutExport->assignRole($role);
    grantApprovalInboxModule($reviewerWithoutExport, 'ViewAny:StockAdjustment');
    grantApprovalInboxModule($reviewerWithoutExport, 'ViewAny:StockMovement');

    expect($inbox->canExportAnyModule($reviewerWithoutExport))->toBeFalse()
        ->and($inbox->exportableRows($reviewerWithoutExport, $rows))->toBeEmpty();

    $this->actingAs($reviewerWithoutExport);

    Livewire::test(RequestApproval::class)
        ->assertTableActionHidden('exportCsv')
        ->assertTableBulkActionHidden('exportSelectedCsv');
});

test('assigned reviewers can open read only details while approval actions require the active level', function (): void {
    $levelOneRole = Role::factory()->create(['name' => 'adjustment_level_one', 'code' => 'adjustment_level_one']);
    $levelTwoRole = Role::factory()->create(['name' => 'adjustment_level_two', 'code' => 'adjustment_level_two']);
    assignRoleToApprovalModule($levelOneRole, ApprovalModule::StockAdjustment, 1);
    assignRoleToApprovalModule($levelTwoRole, ApprovalModule::StockAdjustment, 2);
    $levelOneUser = User::factory()->create();
    grantApprovalInboxModule($levelOneUser, 'ViewAny:StockAdjustment');
    $levelOneUser->assignRole($levelOneRole);

    $levelTwoUser = User::factory()->create();
    $levelTwoUser->assignRole($levelTwoRole);
    grantApprovalInboxModule($levelTwoUser, 'ViewAny:StockAdjustment');

    $adjustment = StockAdjustment::factory()->create([
        'record_status' => RecordStatus::Saved,
        'approval_status' => StockAdjustmentApprovalStatus::Requested,
        'approval_level' => 1,
    ]);
    expect($adjustment->approval_setting_id)->toBe(approvalSettingFor(ApprovalModule::StockAdjustment)->id)
        ->and(app(ApprovalRequestInbox::class)->canReviewDetail($levelTwoUser, $adjustment))->toBeTrue();

    $this->actingAs($levelTwoUser);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('approve')
        ->assertActionHidden('rejectApproval')
        ->assertActionHidden('reviseApproval');

    $this->actingAs($levelOneUser);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('approve')
        ->assertActionVisible('rejectApproval')
        ->assertActionVisible('reviseApproval');

    $unassigned = User::factory()->create();
    grantApprovalInboxModule($unassigned, 'ViewAny:StockAdjustment');
    $this->actingAs($unassigned);

    Livewire::test(ViewStockAdjustment::class, ['record' => $adjustment->getRouteKey()])
        ->assertForbidden();
});

test('void payment reviewers can open payment details but only the active level can decide', function (): void {
    $levelOneRole = Role::factory()->create(['name' => 'void_level_one', 'code' => 'void_level_one']);
    $levelTwoRole = Role::factory()->create(['name' => 'void_level_two', 'code' => 'void_level_two']);
    assignRoleToApprovalModule($levelOneRole, ApprovalModule::VoidPayment, 1);
    assignRoleToApprovalModule($levelTwoRole, ApprovalModule::VoidPayment, 2);

    $levelOneUser = User::factory()->create();
    $levelOneUser->assignRole($levelOneRole);
    grantApprovalInboxModule($levelOneUser, 'ViewAny:Payment');

    $levelTwoUser = User::factory()->create();
    $levelTwoUser->assignRole($levelTwoRole);
    grantApprovalInboxModule($levelTwoUser, 'ViewAny:Payment');

    $requester = User::factory()->create();
    $order = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Paid,
    ]);
    $voidRequest = new PaymentVoidRequest;
    $voidRequest->forceFill([
        'payment_id' => $payment->id,
        'requested_by' => $requester->id,
        'reason' => 'Void request review',
        'approval_setting_id' => approvalSettingFor(ApprovalModule::VoidPayment)->id,
        'approval_status' => 'requested',
        'approval_level' => 1,
    ])->save();

    expect(app(ApprovalRequestInbox::class)->paginate($levelTwoUser, 1, 10)->total())->toBe(1);

    $this->actingAs($levelTwoUser);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('voidPayment')
        ->assertActionHidden('approve')
        ->assertActionHidden('rejectApproval')
        ->assertActionHidden('reviseApproval');

    $this->actingAs($levelOneUser);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('voidPayment')
        ->assertActionVisible('approve')
        ->assertActionVisible('rejectApproval')
        ->assertActionVisible('reviseApproval');
});
