<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice['invoice_number'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 42pt 5pt 30pt 22pt; }
        body { margin: 0; color: #000; font-family: "Invoice Times", "Times New Roman", Times, serif; font-size: 9.65pt; line-height: 1.15; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { padding: 0; vertical-align: top; }
        .header { margin-left: 2.7pt; width: 100%; }
        .company { width: 53%; }
        .logo { display: block; width: 89pt; height: auto; margin-bottom: 8pt; }
        .company-name { font-family: "Invoice Playfair", "Times New Roman", Times, serif; font-size: 10.52pt; font-weight: bold; margin-bottom: 2.5pt; }
        .address { line-height: 18.2pt; white-space: pre-line; }
        .company-phone { margin-top: 6pt; }
        .heading { font-family: "Invoice Garamond", "Times New Roman", Times, serif; font-size: 20.17pt; line-height: 24pt; font-weight: bold; margin: 0 0 7pt; position: relative; top: 1.5pt; }
        .metadata td { height: 18.2pt; }
        .metadata { position: relative; top: 5.3pt; }
        .metadata tr:first-child td:last-child { position: relative; top: 3.7pt; }
        .metadata .label { width: 43%; }
        .metadata .colon { width: 5%; }
        .customers { margin: 14.5pt 0 0 2.7pt; width: 95%; table-layout: auto; }
        .customers td { height: 18.2pt; }
        .customers .label { width: 63.4pt; }
        .customers .colon { width: 8.8pt; }
        .items { margin-top: 19pt; }
        .items thead { display: table-header-group; }
        .items th { border-top: 0.6pt dotted #000; border-bottom: 0.6pt dotted #000; padding: 6.4pt 2pt; text-align: center; font-weight: bold; }
        .items td { padding: 4.35pt 5pt; line-height: 11.2pt; }
        .items tr { page-break-inside: avoid; }
        .items .number { text-align: center; }
        .items .quantity { text-align: center; }
        .items .unit { padding-left: 0; }
        .items .price { padding-right: 20pt; }
        .quantity-heading { position: relative; left: 18pt; }
        .money { white-space: nowrap; text-align: right; }
        .currency { display: inline-block; width: 15%; text-align: left; }
        .amount { display: inline-block; width: 85%; text-align: right; }
        .footer { margin-top: 13pt; page-break-inside: avoid; }
        .words { width: 54%; font-style: italic; padding: 0 15pt 0 24pt; }
        .words-table { table-layout: auto; }
        .words-table .label { width: 50pt; vertical-align: bottom; }
        .words-table .value { line-height: 11.2pt; width: 180pt; }
        .words-table .value div { width: 180pt; }
        .summary { width: 46%; padding-top: 7pt; }
        .summary td { height: 20pt; font-weight: bold; }
        .summary .label { width: 48%; text-align: right; padding-right: 17.3pt; }
        .summary .money { padding: 0 5pt; }
        .summary tr:nth-child(3) td { height: 15pt; }
        .summary .grand .money { border-top: 0.6pt dotted #000; border-bottom: 0.6pt dotted #000; padding-top: 4pt; height: 14pt; }
        .summary .grand .label { padding-top: 4pt; }
        .summary .double-rule td { height: 3pt; font-size: 0; line-height: 0; }
        .summary .double-rule .money { border-bottom: 0.6pt dotted #000; }
        .bank { margin: 62pt 0 0 5pt; font-style: normal; font-weight: bold; }
        .bank p { margin: 0 0 11pt; }
        .bank p:first-child { margin-bottom: 13pt; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td class="company">
                <img class="logo" src="{{ $invoice['logo_data_uri'] ?? public_path('images/logo/agung-farm-logo.png') }}" alt="Agung farm &amp; garden">
                <div class="company-name">@foreach (explode('&', $invoice['company_name']) as $part)@if (! $loop->first)<span style="font-family: 'Invoice Times', 'Times New Roman', Times, serif">&amp;</span>@endif{{ $part }}@endforeach</div>
                <div class="address">{{ $invoice['address'] }}</div>
                <div class="company-phone">Phone : {{ $invoice['company_phone'] ?: '-' }}</div>
            </td>
            <td>
                <h1 class="heading">INVOICE</h1>
                <table class="metadata">
                    @foreach ([
                        'Number' => $invoice['invoice_number'],
                        'Inv. Date' => \Carbon\CarbonImmutable::parse($invoice['invoice_date'])->locale('en')->translatedFormat('j F Y'),
                        'Payment Term' => 'Transfer',
                        'Due Date' => \Carbon\CarbonImmutable::parse($invoice['due_date'])->locale('en')->translatedFormat('j F Y'),
                        'Currency' => 'IDR',
                    ] as $label => $value)
                        <tr><td class="label">{{ $label }}</td><td class="colon">:</td><td>{{ $value }}</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
    <table class="customers">
        <tr><td style="width: 63.4pt">Customer</td><td style="width: 8.8pt">:</td><td>{{ $invoice['customer_name'] }}</td></tr>
        <tr><td>No. HP</td><td>:</td><td>{{ $invoice['customer_phone'] ?: '-' }}</td></tr>
    </table>
    <table class="items">
        <thead>
            <tr><th style="width: 4.3%">No.</th><th style="width: 36.2%">Detail Produk</th><th style="width: 5.5%"><span class="quantity-heading">Qty</span></th><th style="width: 7.6%"></th><th style="width: 22.4%">Harga Satuan</th><th style="width: 24%">Sub Total</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice['items'] as $item)
                <tr>
                    <td class="number">{{ $loop->iteration }}</td>
                    <td>{{ $item['description'] }}</td>
                    <td class="quantity">{{ $item['quantity'] }}</td>
                    <td class="unit">{{ $item['unit'] }}</td>
                    <td class="money price"><span class="currency">Rp</span><span class="amount">{{ \App\Filament\Support\Rupiah::format($item['unit_price']) }} / {{ $item['price_quantity'] ?? '1' }} {{ $item['unit'] ?? '' }}</span></td>
                    <td class="money"><span class="currency">Rp</span><span class="amount">{{ \App\Filament\Support\Rupiah::format($item['subtotal']) }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table class="footer">
        <tr>
            <td class="words">
                <table class="words-table"><tr><td class="label">Terbilang&nbsp; :</td><td class="value"><div>{{ $invoice['total_words'] }}</div></td></tr></table>
                <div class="bank">
                    <p>Pembayaran ke Rekening :</p>
                    <p>{{ $invoice['bank_name'] }} {{ $invoice['bank_account'] }}</p>
                    <p>a/n {{ $invoice['bank_holder'] }}</p>
                </div>
            </td>
            <td class="summary">
                <table>
                    <tr><td class="label">Total</td><td class="money"><span class="currency">Rp</span><span class="amount">{{ \App\Filament\Support\Rupiah::format($invoice['subtotal']) }}</span></td></tr>
                    <tr><td class="label">Diskon</td><td class="money">@if (($invoice['discount_amount'] ?? 0) > 0)<span class="currency">Rp</span><span class="amount">{{ \App\Filament\Support\Rupiah::format($invoice['discount_amount']) }}</span>@endif</td></tr>
                    <tr><td class="label">Biaya Kirim</td><td class="money">@if ((float) $invoice['shipping_cost'] !== 0.0)<span class="currency">Rp</span><span class="amount">{{ \App\Filament\Support\Rupiah::format($invoice['shipping_cost']) }}</span>@endif</td></tr>
                    <tr class="grand"><td class="label">Grand Total</td><td class="money"><span class="currency">Rp</span><span class="amount">{{ \App\Filament\Support\Rupiah::format($invoice['total']) }}</span></td></tr>
                    <tr class="double-rule"><td></td><td class="money"></td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
