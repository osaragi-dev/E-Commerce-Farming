<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class PaymentReceiptController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Payment $payment): View
    {
        Gate::authorize('view', $payment);
        abort_unless($payment->status === PaymentStatus::Paid, 403, 'Only paid payments have a receipt.');

        $payment->load(['order.customer', 'order.items', 'verifiedBy']);
        abort_if($payment->order === null, 404);

        return view('payments.receipt', ['payment' => $payment, 'order' => $payment->order]);
    }
}
