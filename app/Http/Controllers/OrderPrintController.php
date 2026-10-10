<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\EscPosReceipt;
use App\Services\QzReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderPrintController extends Controller
{
    public function certificate(Order $order, QzReceipt $receipt): JsonResponse
    {
        $this->authorizeOrder($order);

        return response()->json(['certificate' => $receipt->certificate()])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Order $order, QzReceipt $receipt): JsonResponse
    {
        $this->authorizeOrder($order);
        $order->load(['customer', 'items', 'payment']);

        $data = $request->validate([
            'printer_name' => ['sometimes', 'required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
        ]);

        return response()->json($receipt->job($order, $data['printer_name'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function rawbt(Order $order, EscPosReceipt $receipt): JsonResponse
    {
        $this->authorizeOrder($order);
        $order->load(['customer', 'items', 'payment']);

        return response()->json(['data' => base64_encode($receipt->raw($order))])->header('Cache-Control', 'no-store');
    }

    private function authorizeOrder(Order $order): void
    {
        Gate::authorize('view', $order);
    }
}
