<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\ApprovalLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaymentVoidWorkflow
{
    public function lockPayment(Payment $payment): Payment
    {
        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
        $payment->setRelation('order', $order);

        return $payment;
    }

    public function isEligible(Payment $payment, bool $cancelOrder = false): bool
    {
        return $payment->status === PaymentStatus::Paid
            && ($cancelOrder
                ? $payment->order?->canBeCancelled() && $payment->order->payment?->is($payment)
                : $payment->canBeVoided());
    }

    public function assertEligible(Payment $payment, bool $cancelOrder = false): void
    {
        app(SystemFreezeGuard::class)->assertNotFrozen();
        if (! $this->isEligible($payment, $cancelOrder)) {
            throw ValidationException::withMessages(['void' => 'This payment is no longer eligible for void.']);
        }
    }

    public function request(Payment $payment, User $actor, string $reason, bool $cancelOrder = false): PaymentVoidRequest
    {
        Gate::forUser($actor)->authorize('Void:Payment');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($payment, $actor, $reason, $cancelOrder): PaymentVoidRequest {
            $payment = $this->lockPayment($payment);
            if ($cancelOrder) {
                Gate::forUser($actor)->authorize('update', $payment->order);
            }
            $this->assertEligible($payment, $cancelOrder);
            if ($payment->hasActiveVoidRequest()) {
                throw ValidationException::withMessages(['void' => 'A void request is already active.']);
            }
            $request = new PaymentVoidRequest(['payment_id' => $payment->id, 'requested_by' => $actor->id, 'reason' => $reason, 'cancel_order' => $cancelOrder]);
            $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($request);
            $request->approval_status = $requiresApproval ? 'requested' : 'approved';
            $request->approval_level = $requiresApproval ? 1 : 0;
            $request->save();
            $this->log($request, $actor, 'requested', $reason);
            if (! $requiresApproval) {
                $this->complete($request, $actor);
                $this->log($request, $actor, 'approved', 'No active approval levels configured.');
            }

            return $request;
        });
    }

    public function canManage(PaymentVoidRequest $request, User $actor): bool
    {
        return in_array($request->approval_status, PaymentVoidRequest::ACTIVE_STATUSES, true)
            && ($request->requested_by === $actor->id || $actor->hasAnyRole(['admin', 'super_admin'])
                || $actor->roles()->where('code', 'FARMING_ADMIN')->exists());
    }

    public function resubmit(PaymentVoidRequest $request, User $actor, string $reason): void
    {
        $reason = $this->reason($reason);
        DB::transaction(function () use ($request, $actor, $reason): void {
            $payment = $this->lockPayment($request->payment);
            $request = PaymentVoidRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($request->requested_by === $actor->id && $request->approval_status === 'revised', 403);
            Gate::forUser($actor)->authorize('Void:Payment');
            if ($request->cancel_order) {
                Gate::forUser($actor)->authorize('update', $payment->order);
            }
            $this->assertEligible($payment, $request->cancel_order);
            $request->update(['reason' => $reason, 'approval_status' => 'requested', 'approval_level' => 1, 'updated_by' => $actor->id]);
            $this->log($request, $actor, 'resubmitted', $reason);
        });
    }

    public function withdraw(PaymentVoidRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $this->lockPayment($request->payment);
            $request = PaymentVoidRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($this->canManage($request, $actor), 403);
            app(SystemFreezeGuard::class)->assertNotFrozen();
            $request->update(['approval_status' => 'cancelled', 'updated_by' => $actor->id]);
            $this->log($request, $actor, 'withdrawn', 'Void request withdrawn.');
        });
    }

    public function complete(PaymentVoidRequest $request, User $actor): void
    {
        if ($request->cancel_order) {
            $request->payment->order->cancel($actor, $request->reason, $request);
        } else {
            $request->payment->void($actor);
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:5000']])->validate();

        return $reason;
    }

    private function log(PaymentVoidRequest $request, ?User $actor, string $action, string $notes): void
    {
        ApprovalLog::create(['approvable_type' => PaymentVoidRequest::class, 'approvable_id' => $request->id, 'module_code' => 'void_payment', 'level' => $request->approval_level, 'action' => $action, 'acted_by' => $actor?->id, 'notes' => $notes]);
    }
}
