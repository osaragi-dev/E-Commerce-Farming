<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1b2b22; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #6b7a70; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #e4e0d4; }
        th { background: #f4f6ee; font-size: 11px; text-transform: uppercase; }
        .text-right { text-align: right; }
        .totals td { border: none; padding: 3px 8px; }
        .totals .label { color: #6b7a70; }
        .totals .grand { font-size: 14px; font-weight: bold; color: #153a2d; }
        .section { margin-top: 16px; }
    </style>
</head>
<body>
    <h1>{{ $company->company_name }}</h1>
    @if ($company->address)
        <p class="muted">{{ $company->address }}</p>
    @endif

    <div class="section">
        <table>
            <tr>
                <td><strong>Order Number</strong><br>{{ $order->order_number }}</td>
                <td><strong>Order Date</strong><br>{{ $order->created_at->format('d/m/Y H:i') }}</td>
                <td><strong>Payment Method</strong><br>{{ strtoupper($order->payment?->method?->value ?? '-') }}</td>
                <td><strong>Shipping Method</strong><br>{{ $order->shipping_method?->getLabel() ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <strong>Recipient</strong><br>
        {{ $order->recipient_name }}<br>
        {{ $order->recipient_phone }}<br>
        <strong>{{ $order->shipping_method?->getLocationLabel() ?? 'Shipping Location' }}</strong><br>
        {{ $order->shipping_address }}
    </div>

    <div class="section">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Variant</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $item->product_name }}</td>
                        <td>{{ $item->variant_name }}</td>
                        <td class="text-right">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->unit_price) }} / {{ \App\Support\Quantity::format($item->price_quantity) }} {{ $item->unit }}</td>
                        <td class="text-right">{{ \App\Support\Quantity::format($item->quantity) }}</td>
                        <td class="text-right">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section" style="width: 260px; margin-left: auto;">
        <table class="totals">
            <tr>
                <td class="label">Subtotal</td>
                <td class="text-right">{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->subtotal) }}</td>
            </tr>
            <tr>
                <td class="label">Discount</td>
                <td class="text-right">-{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->discount_amount) }}</td>
            </tr>
            <tr>
                <td class="label">Shipping Cost</td>
                <td class="text-right">{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->shipping_cost) }}</td>
            </tr>
            <tr>
                <td class="grand">Total</td>
                <td class="text-right grand">{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->total) }}</td>
            </tr>
        </table>
    </div>

    <p class="muted section" style="text-align:center; margin-top:40px;">This is a computer-generated invoice from {{ $company->company_name }}.</p>
</body>
</html>
