<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Receipt {{ $payment->payment_number }}</title>
    @include('payments.receipt-styles')
</head>
<body>
    @unless (request()->boolean('embedded'))
    <div class="toolbar" data-printer-controls data-default-printer="{{ config('qz.printer') }}">
        <button type="button" data-print-receipt data-receipt-url="{{ route('admin.payments.receipt', $payment) }}" data-job-url="{{ route('admin.payments.print', $payment) }}" data-certificate-url="{{ route('admin.payments.print-certificate', $payment) }}" data-rawbt-url="{{ route('admin.payments.rawbt', $payment) }}">Print Receipt</button>
        <button type="button" onclick="window.print()">Print with Browser</button>
        <p data-print-status role="status" aria-live="polite"></p>
        <a data-rawbt-link hidden>Open RawBT</a>
        <noscript>Enable JavaScript to print this receipt.</noscript>
    </div>
    @endunless
    <main class="receipt">
        <header>
            <img class="logo" src="{{ asset('images/logo/agung-farm-receipt-logo.png') }}" alt="Agung Farm &amp; Garden">
        </header>
        <dl>
            <div><dt>Order</dt><dd>{{ $order->order_number }}</dd></div>
            <div><dt>Payment</dt><dd>{{ $payment->payment_number }}</dd></div>
            <div><dt>Paid at</dt><dd>{{ $payment->verified_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '—' }} WIB</dd></div>
            @if (filled($payment->verifiedBy?->name))
                <div><dt>Cashier</dt><dd>{{ $payment->verifiedBy->name }}</dd></div>
            @endif
            <div><dt>Customer</dt><dd>{{ filled($order->recipient_name) ? $order->recipient_name : ($order->customer?->name ?? '—') }}</dd></div>
        </dl>
        <table aria-label="Purchased items">
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td colspan="2">
                            <div class="item-name">{{ filled($item->variant_name) ? $item->variant_name : '—' }}</div>
                            <div class="item-details">
                                <span>{{ \App\Support\Quantity::format($item->quantity) }} {{ $item->unit }} x {{ \App\Filament\Support\Rupiah::formatForReceipt($item->unit_price) }}</span>
                                <span class="item-subtotal">{{ \App\Filament\Support\Rupiah::formatForReceipt($item->subtotal) }}</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <dl class="totals">
            <div><dt>Subtotal</dt><dd>{{ \App\Filament\Support\Rupiah::formatForReceipt($order->subtotal) }}</dd></div>
            @if ($order->discount_amount > 0)
                <div><dt>Discount</dt><dd>-{{ \App\Filament\Support\Rupiah::formatForReceipt($order->discount_amount) }}</dd></div>
            @endif
            @if ($order->shipping_cost > 0)
                <div><dt>Shipping</dt><dd>{{ \App\Filament\Support\Rupiah::formatForReceipt($order->shipping_cost) }}</dd></div>
            @endif
            <div class="total"><dt>TOTAL</dt><dd>{{ \App\Filament\Support\Rupiah::formatForReceipt($order->total) }}</dd></div>
            <div class="payment-method"><dt>Payment Method</dt><dd>{{ $payment->method->getLabel() }}</dd></div>
        </dl>
        <footer>Thank you for your purchase!<br>Agung Farm &amp; Garden</footer>
    </main>
    @unless (request()->boolean('embedded'))
        @vite('resources/js/payment-receipt.js')
    @endunless
</body>
</html>
