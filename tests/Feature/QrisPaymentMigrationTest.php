<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('legacy unpaid qris migration adds deadlines and returns orders to waiting payment idempotently', function (): void {
    $proofUploadHours = 24;
    $createdAt = now()->subHours(6)->startOfSecond();

    PaymentSetting::current()->update(['proof_resubmission_hours' => $proofUploadHours]);

    $order = Order::factory()->create([
        'payment_method' => PaymentMethod::Qris,
        'status' => OrderStatus::ReadyToProcess,
    ]);
    $payment = Payment::factory()->for($order)->create([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Unpaid,
        'proof_upload_deadline_at' => null,
    ]);

    DB::table('payments')->where('id', $payment->id)->update(['created_at' => $createdAt]);

    $migration = require database_path('migrations/2026_09_26_090915_normalize_legacy_qris_unpaid_payments.php');
    $migration->up();

    $deadline = DB::table('payments')->where('id', $payment->id)->value('proof_upload_deadline_at');

    expect(Carbon::parse($deadline)->diffInSeconds($createdAt->copy()->addHours($proofUploadHours)))->toBe(0.0)
        ->and($order->refresh()->status)->toBe(OrderStatus::WaitingPayment)
        ->and(DB::table('order_status_logs')->where('order_id', $order->id)->count())->toBe(1);

    $migration->up();

    expect(DB::table('payments')->where('id', $payment->id)->value('proof_upload_deadline_at'))->toBe($deadline)
        ->and($order->refresh()->status)->toBe(OrderStatus::WaitingPayment)
        ->and(DB::table('order_status_logs')->where('order_id', $order->id)->count())->toBe(1);
});
