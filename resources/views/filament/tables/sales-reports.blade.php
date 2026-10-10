<div class="fi-ta-content flex flex-col gap-5 p-4 sm:p-6">
    @foreach ($this->getTableRecords() as $payment)
        @php($order = $payment->order)
        <article wire:key="payment-{{ $payment->getKey() }}" data-row-type="Transaction" class="overflow-hidden rounded-xl border sales-report-card border-gray-200 dark:border-white/10" aria-label="Transaction {{ $payment->payment_number }}">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-5 py-4 dark:border-white/10 dark:bg-white/5">
                <div class="flex min-w-0 flex-col gap-1">
                    <h3 class="break-words text-base font-semibold text-gray-950 dark:text-white">{{ $order?->order_number ?? $payment->payment_number }}</h3>
                    <p class="text-sm sales-report-muted">
                        {{ $payment->created_at?->timezone('Asia/Jakarta')->format('d M Y, H:i:s') }} · {{ $payment->createdBy?->name ?? 'Not recorded' }}
                    </p>
                </div>
                <x-filament::badge :color="$payment->status->getColor()">{{ $payment->status->getLabel() }}</x-filament::badge>
            </header>

            <div class="flex flex-col gap-5 p-5">
                <div class="overflow-x-auto">
                    <dl class="flex min-w-max justify-between gap-6 text-sm">
                        @foreach (['Customer' => $order?->recipient_name ?? 'Not recorded', 'Items' => $order?->items->count() ?? 0, 'Payment Method' => $payment->method->getLabel(), 'Reference' => $payment->payment_number] as $label => $value)
                            <div class="flex max-w-56 shrink-0 flex-col gap-1">
                                <dt class="sales-report-muted">{{ $label }}</dt>
                                <dd class="break-words font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                        @foreach (['Subtotal' => $order?->subtotal, 'Shipping Cost' => $order?->shipping_cost, 'Total' => $order?->total, 'Payment Amount' => $payment->amount] as $label => $value)
                            <div class="flex shrink-0 flex-col gap-1 text-end">
                                <dt class="sales-report-muted">{{ $label }}</dt>
                                <dd class="font-semibold text-gray-950 tabular-nums dark:text-white">{{ \App\Filament\Support\Rupiah::formatWithPrefix($value) ?? 'Not recorded' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full min-w-max text-sm" aria-label="Order items for {{ $order?->order_number ?? $payment->payment_number }}">
                        <thead class="sales-report-item-heading">
                            <tr>
                                @foreach (['Product', 'Variant', 'Unit', 'Unit Price', 'Quantity', 'Line Total'] as $column)
                                    <th scope="col" @class(['px-4 py-3 font-semibold', 'text-end' => $loop->index >= 3, 'text-start' => $loop->index < 3])>{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="sales-report-secondary divide-y divide-gray-100 dark:divide-white/10">
                            @forelse ($order?->items ?? [] as $item)
                                <tr data-row-type="Item">
                                    <td class="max-w-72 whitespace-normal px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $item->product_name }}</td>
                                    <td class="max-w-56 whitespace-normal px-4 py-3">{{ $item->variant_name ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $item->unit ?? '-' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end tabular-nums">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->unit_price) }} / {{ \App\Support\Quantity::format($item->price_quantity) }} {{ $item->unit }}</td>
                                    <td class="px-4 py-3 text-end tabular-nums">{{ \App\Support\Quantity::format($item->quantity) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end font-medium tabular-nums">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->subtotal) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-4 sales-report-muted">No order items</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (filled($order?->notes))
                    <dl class="flex flex-col gap-1 text-sm">
                        <dt class="font-medium sales-report-muted">Order Note</dt>
                        <dd class="whitespace-pre-line break-words sales-report-secondary">{{ $order->notes }}</dd>
                    </dl>
                @endif
            </div>
        </article>
    @endforeach
</div>
