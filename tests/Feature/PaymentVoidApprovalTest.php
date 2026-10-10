<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalWorkflow;
use App\Services\PaymentVoidWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::factory()->create(['code' => 'FARMING_ADMIN', 'name' => 'Admin Farming']));
    $this->actor->givePermissionTo(Permission::findOrCreate('Void:Payment', 'web'));
    $this->actingAs($this->actor);
    $this->order = Order::factory()->create(['status' => OrderStatus::ReadyToProcess]);
    $this->payment = Payment::factory()->create(['order_id' => $this->order->id, 'status' => PaymentStatus::Paid]);
    $this->setting = ApprovalSetting::where('module_code', 'void_payment')->firstOrFail();
});

test('void approval traverses all levels and preserves order and stock state', function (): void {
    ApprovalSettingLevel::factory()->count(2)->create(['approval_setting_id' => $this->setting->id]);
    $before = $this->order->fresh()->getAttributes();
    $request = app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate payment');
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->order->fresh()->canStartPacking())->toBeFalse();
    app(ApprovalWorkflow::class)->approve($request, $this->actor);
    expect($request->fresh()->approval_level)->toBe(2)->and($this->payment->fresh()->status)->toBe(PaymentStatus::Paid);
    app(ApprovalWorkflow::class)->approve($request->fresh(), $this->actor);
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Void)
        ->and($this->order->fresh()->getAttributes())->toBe($before)
        ->and($request->fresh()->logs()->count())->toBe(3);
    expect(fn () => app(ApprovalWorkflow::class)->approve($request->fresh(), $this->actor))->toThrow(ValidationException::class);
});

test('void without active levels is immediate', function (bool $active): void {
    $this->setting->update(['is_active' => $active]);
    $request = app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate');
    expect($request->approval_status)->toBe('approved')->and($this->payment->fresh()->status)->toBe(PaymentStatus::Void);
})->with([true, false]);

test('void revision can be resubmitted and rejection releases the hold', function (): void {
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    $service = app(PaymentVoidWorkflow::class);
    $request = $service->request($this->payment, $this->actor, 'Original reason');
    expect(fn () => $service->request($this->payment, $this->actor, 'Duplicate'))->toThrow(ValidationException::class);
    app(ApprovalWorkflow::class)->revise($request, $this->actor, 'Explain why');
    expect($this->order->fresh()->canStartPacking())->toBeFalse();
    $service->resubmit($request->fresh(), $this->actor, 'Corrected reason');
    expect($request->fresh()->approval_level)->toBe(1);
    app(ApprovalWorkflow::class)->reject($request->fresh(), $this->actor, 'Not needed');
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->payment->fresh()->hasActiveVoidRequest())->toBeFalse();
    $next = $service->request($this->payment, $this->actor, 'Another request');
    $service->withdraw($next, $this->actor);
    expect($next->fresh()->approval_status)->toBe('cancelled')->and($this->payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

test('cancel order cannot bypass an active void request', function (): void {
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    $request = app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate');
    $this->actor->givePermissionTo(Permission::findOrCreate('Update:Order', 'web'));
    expect(fn () => $this->order->cancel($this->actor, 'Cancel order'))->toThrow(ValidationException::class);
    expect($request->fresh()->approval_status)->toBe('requested')
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

test('unassigned users cannot approve a void request', function (): void {
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    $request = app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate');
    $other = User::factory()->create();
    expect(app(ApprovalWorkflow::class)->canApprove($request, $other))->toBeFalse();
    expect(fn () => app(ApprovalWorkflow::class)->approve($request, $other))->toThrow(ValidationException::class);
});

test('payment detail exposes approval actions for the farming admin', function (): void {
    $this->actor->givePermissionTo(Permission::findOrCreate('View:Payment', 'web'));
    $this->actor->givePermissionTo(Permission::findOrCreate('ViewAny:Payment', 'web'));
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    $request = app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate');
    Livewire::test(ViewPayment::class, ['record' => $this->payment->id])
        ->assertSuccessful()
        ->assertActionVisible('approve')
        ->assertActionVisible('rejectApproval')
        ->assertActionVisible('reviseApproval')
        ->callAction('approve')
        ->assertHasNoActionErrors();
    expect($request->fresh()->approval_status)->toBe('approved')
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Void);
});

test('pending void cannot bypass approval and blocks packing', function (): void {
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate');
    expect(fn () => $this->payment->void($this->actor))->toThrow(ValidationException::class);
    expect(fn () => $this->order->fresh()->advanceStatus($this->actor))->toThrow(RuntimeException::class);
    expect($this->order->fresh()->status)->toBe(OrderStatus::ReadyToProcess);
});

test('void decisions require notes and withdrawal cannot be performed by another requester', function (): void {
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    $request = app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate');
    expect(fn () => app(ApprovalWorkflow::class)->reject($request, $this->actor, ' '))->toThrow(ValidationException::class);
    expect(fn () => app(ApprovalWorkflow::class)->revise($request, $this->actor, ' '))->toThrow(ValidationException::class);
    expect(fn () => app(PaymentVoidWorkflow::class)->withdraw($request, User::factory()->create()))->toThrow(HttpException::class);
    expect($request->fresh()->approval_status)->toBe('requested');
});

test('void requests require permission and a nonempty reason', function (): void {
    expect(fn () => app(PaymentVoidWorkflow::class)->request($this->payment, User::factory()->create(), 'Duplicate'))->toThrow(AuthorizationException::class);
    expect(fn () => app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, ' '))->toThrow(ValidationException::class);
});

test('void request validates current payment and order eligibility', function (string $change): void {
    if ($change === 'packing') {
        $this->order->update(['status' => OrderStatus::Packing]);
    } else {
        $this->payment->update(['status' => PaymentStatus::Unpaid]);
    }
    expect(fn () => app(PaymentVoidWorkflow::class)->request($this->payment->fresh(), $this->actor, 'Duplicate'))->toThrow(ValidationException::class);
})->with(['packing', 'unpaid']);

test('assigned approver can revise and requester can resubmit from the payment page', function (): void {
    foreach (['View:Payment', 'ViewAny:Payment'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $level = ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    $reviewer = User::factory()->create();
    $reviewer->assignRole($level->role);
    $request = app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Original');
    app(ApprovalWorkflow::class)->revise($request, $reviewer, 'More information required');
    Livewire::test(ViewPayment::class, ['record' => $this->payment->id])
        ->assertActionVisible('resubmitVoid')
        ->callAction('resubmitVoid', ['reason' => 'Corrected'])
        ->assertHasNoActionErrors()
        ->assertActionVisible('approve');
    expect($request->fresh()->reason)->toBe('Corrected');
});

test('submitting void from payment detail opens its approval workflow', function (): void {
    foreach (['View:Payment', 'ViewAny:Payment'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    Livewire::test(ViewPayment::class, ['record' => $this->payment->id])
        ->callAction('voidPayment', ['reason' => 'Duplicate'])
        ->assertHasNoActionErrors()
        ->assertRedirect(PaymentResource::getUrl('view', ['record' => $this->payment]));
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->payment->voidRequests()->sole()->approval_status)->toBe('requested');
});

test('payments table shows void approval beside the payment status', function (): void {
    $this->actor->givePermissionTo(Permission::findOrCreate('ViewAny:Payment', 'web'));
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $this->setting->id]);
    app(PaymentVoidWorkflow::class)->request($this->payment, $this->actor, 'Duplicate');

    Livewire::test(ManagePayments::class)
        ->assertSee('Void: Requested')
        ->assertDontSee('Void Approval');
});
