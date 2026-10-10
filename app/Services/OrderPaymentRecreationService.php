<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrderPaymentRecreationService
{
    public function recreate(Order $order, PaymentMethod $method, User $actor, string $expectedPaymentId): Payment
    {
        Gate::forUser($actor)->authorize('Create:Payment');
        Gate::forUser($actor)->authorize('update', $order);
        app(SystemFreezeGuard::class)->assertNotFrozen();

        if ($method === PaymentMethod::Invoice) {
            throw ValidationException::withMessages(['method' => 'Choose Cash, QRIS, or Bank Transfer.']);
        }

        return DB::transaction(function () use ($order, $method, $actor, $expectedPaymentId): Payment {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $previous = $lockedOrder->payment()->lockForUpdate()->first();
            $lockedOrder->setRelation('payment', $previous);

            if (! $lockedOrder->canRecreatePayment() || $previous->id !== $expectedPaymentId) {
                throw ValidationException::withMessages(['method' => 'This order is no longer eligible. Refresh before creating a payment.']);
            }

            $payment = $lockedOrder->payments()->create([
                'attempt_number' => $previous->attempt_number + 1,
                'method' => $method,
                'amount' => $lockedOrder->total,
                'status' => PaymentStatus::Unpaid,
                'created_by' => $actor->id,
            ]);
            $lockedOrder->update(['payment_method' => $method, 'updated_by' => $actor->id]);
            $order->unsetRelation('payment');

            return $payment;
        });
    }
}
