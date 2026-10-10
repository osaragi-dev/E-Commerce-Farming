<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function show(Order $order): Response
    {
        abort_unless($order->customer_id === auth('customer')->id(), 403);

        $order->load(['items', 'payment', 'customer']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'company' => CompanyProfile::current(),
        ]);

        return $pdf->stream("invoice-{$order->order_number}.pdf");
    }
}
