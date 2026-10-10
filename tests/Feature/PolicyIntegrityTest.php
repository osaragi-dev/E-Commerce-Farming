<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Guard custom policy methods against accidental policy regeneration.
 * RoleSeeder generates permissions only so these methods remain intact.
 */
test('OrderPolicy still has its custom advanceStatus method', function (): void {
    expect(method_exists(OrderPolicy::class, 'advanceStatus'))
        ->toBeTrue('OrderPolicy::advanceStatus() is missing.');
});

test('PaymentPolicy still has its custom verify, reject and void methods', function (): void {
    expect(method_exists(PaymentPolicy::class, 'verify'))
        ->toBeTrue('PaymentPolicy::verify() is missing — shield:generate has wiped it again (see VerifyPaymentAction). Re-add it.')
        ->and(method_exists(PaymentPolicy::class, 'reject'))
        ->toBeTrue('PaymentPolicy::reject() is missing — shield:generate has wiped it again (see RejectPaymentAction). Re-add it.')
        ->and(method_exists(PaymentPolicy::class, 'void'))
        ->toBeTrue('PaymentPolicy::void() is missing — shield:generate has wiped it again (see VoidPaymentAction). Re-add it.');
});

/**
 * This test does NOT rely on the Policy method names existing at all — it
 * checks the raw permission strings directly via Gate, exactly the way
 * AdvanceOrderStatusAction/VerifyPaymentAction/
 * RejectPaymentAction/VoidPaymentAction now call ->authorize(). This is the
 * actual resilience mechanism (Spatie's Gate::before intercepts a matching
 * permission string before Laravel ever tries to resolve a Policy method —
 * see PermissionRegistrar::registerPermissions()), so even if the two
 * tests above ever start failing again (Policy methods wiped), the real
 * Admin-facing buttons keep working as long as THIS test still passes.
 */
test('a non-super-admin with the custom permissions passes Gate checks via the raw permission string, independent of Policy method names', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $actor = User::factory()->create();
    $order = Order::factory()->create();
    $payment = Payment::factory()->for($order)->create();

    $permissions = [
        'AdvanceStatus:Order',
        'Verify:Payment',
        'Reject:Payment',
        'Void:Payment',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    expect(Gate::forUser($actor)->allows('AdvanceStatus:Order', $order))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('Verify:Payment', $payment))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('Reject:Payment', $payment))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('Void:Payment', $payment))->toBeFalse();

    $actor->givePermissionTo($permissions);

    expect(Gate::forUser($actor)->allows('AdvanceStatus:Order', $order))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('Verify:Payment', $payment))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('Reject:Payment', $payment))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('Void:Payment', $payment))->toBeTrue();
});
