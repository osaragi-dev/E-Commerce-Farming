<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\EscPosReceipt;
use App\Services\QzReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentPrintController extends Controller
{
    public function certificate(Payment $payment, QzReceipt $receipt): JsonResponse
    {
        $this->authorizePayment($payment);

        return response()->json(['certificate' => $receipt->certificate()])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Payment $payment, QzReceipt $receipt): JsonResponse
    {
        $this->authorizePayment($payment);
        $payment->load(['order.customer', 'order.items', 'verifiedBy']);
        abort_if($payment->order === null, 404);

        $data = $request->validate([
            'printer_name' => ['sometimes', 'required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
        ]);

        return response()->json($receipt->job($payment, $data['printer_name'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function rawbt(Payment $payment, EscPosReceipt $receipt): JsonResponse
    {
        $this->authorizePayment($payment);
        $payment->load(['order.customer', 'order.items', 'verifiedBy']);
        abort_if($payment->order === null, 404);

        return response()->json(['data' => base64_encode($receipt->raw($payment))])->header('Cache-Control', 'no-store');
    }

    private function authorizePayment(Payment $payment): void
    {
        Gate::authorize('view', $payment);
        abort_unless($payment->status === PaymentStatus::Paid, 403, 'Only paid payments have a receipt.');
    }
}
