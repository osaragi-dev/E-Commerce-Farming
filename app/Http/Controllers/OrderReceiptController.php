<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\View\View;

class OrderReceiptController extends Controller
{
    public function __invoke(Order $order): View
    {
        abort_unless(request()->user()?->can('View:Order'), 403);

        $order->load(['customer', 'items', 'payment']);

        return view('orders.receipt', compact('order'));
    }
}
