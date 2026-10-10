<?php

use App\Enums\ApprovalModule;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\ApprovalWorkflow;
use App\Services\PaymentVoidWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('approval requests notify assigned approvers and notify the next level after approval', function (): void {
    $levelOneRole = Role::findOrCreate('approval_level_one', 'web');
    $levelTwoRole = Role::findOrCreate('approval_level_two', 'web');
    $levelOneApprover = User::factory()->create(['is_active' => true]);
    $levelTwoApprover = User::factory()->create(['is_active' => true]);
    $unassignedUser = User::factory()->create(['is_active' => true]);
    $levelOneApprover->assignRole($levelOneRole);
    $levelTwoApprover->assignRole($levelTwoRole);

    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $levelOneRole->id,
        'level' => 1,
    ]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $levelTwoRole->id,
        'level' => 2,
    ]);

    $movement = StockMovement::factory()->create();
    $levelOneNotification = $levelOneApprover->notifications()
        ->where('type', ApprovalRequestedNotification::class)
        ->sole();

    expect($movement->approval_status)->toBe(StockMovementApprovalStatus::Requested)
        ->and($levelOneNotification->data['title'])->toBe('Approval requested')
        ->and($levelOneNotification->data['approval_level'])->toBe(1)
        ->and($levelOneNotification->data['actions'][0]['url'])->toBe(
            StockMovementResource::getUrl('view', ['record' => $movement]),
        )
        ->and($levelTwoApprover->notifications()->where('type', ApprovalRequestedNotification::class)->exists())->toBeFalse()
        ->and($unassignedUser->notifications()->where('type', ApprovalRequestedNotification::class)->exists())->toBeFalse();

    app(ApprovalWorkflow::class)->approve($movement, $levelOneApprover);

    $levelTwoNotification = $levelTwoApprover->notifications()
        ->where('type', ApprovalRequestedNotification::class)
        ->sole();

    expect($movement->fresh()->approval_status)->toBe(StockMovementApprovalStatus::Pending)
        ->and($movement->fresh()->approval_level)->toBe(2)
        ->and($levelTwoNotification->data['approval_level'])->toBe(2)
        ->and($unassignedUser->notifications()->where('type', ApprovalRequestedNotification::class)->exists())->toBeFalse();
});

test('stock adjustment and stock opname requests notify their level one approvers', function (ApprovalModule $module): void {
    $role = Role::findOrCreate('approver_'.$module->value, 'web');
    $approver = User::factory()->create(['is_active' => true]);
    $approver->assignRole($role);

    $setting = ApprovalSetting::query()
        ->where('module_code', $module->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
        'level' => 1,
    ]);

    $record = match ($module) {
        ApprovalModule::StockAdjustment => StockAdjustment::factory()->create([
            'approval_setting_id' => $setting->id,
        ]),
        ApprovalModule::StockOpname => StockOpnameSession::factory()->create([
            'approval_setting_id' => $setting->id,
            'status' => StockOpnameStatus::Counting,
        ]),
        default => throw new LogicException('Unsupported approval module for this test.'),
    };

    expect($approver->notifications()->where('type', ApprovalRequestedNotification::class)->sole()->data)
        ->toHaveKey('module', $module->value)
        ->toHaveKey('approval_level', 1);
})->with([
    'stock adjustments' => ApprovalModule::StockAdjustment,
    'stock opnames' => ApprovalModule::StockOpname,
]);

test('void payment requests notify the assigned approver', function (): void {
    $role = Role::findOrCreate('void_payment_approver', 'web');
    $approver = User::factory()->create(['is_active' => true]);
    $approver->assignRole($role);
    $requester = User::factory()->create();
    $requester->givePermissionTo(Permission::findOrCreate('Void:Payment', 'web'));
    $this->actingAs($requester);

    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::VoidPayment->value)
        ->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
        'level' => 1,
    ]);
    $order = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'status' => PaymentStatus::Paid,
    ]);

    $request = app(PaymentVoidWorkflow::class)->request($payment, $requester, 'Duplicate payment');

    expect($approver->notifications()->where('type', ApprovalRequestedNotification::class)->sole()->data)
        ->toHaveKey('module', ApprovalModule::VoidPayment->value)
        ->toHaveKey('approval_level', 1)
        ->and($request->approval_status)->toBe('requested');
});
