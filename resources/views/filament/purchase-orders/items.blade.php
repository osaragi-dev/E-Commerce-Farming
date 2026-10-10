<div x-data="{ search: '' }" class="space-y-3">
    <label class="sr-only" for="purchase-order-item-search">Search items</label>
    <input
        id="purchase-order-item-search"
        type="search"
        x-model.debounce.200ms="search"
        placeholder="Search items..."
        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
    >

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Item</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600 dark:text-gray-300">PO Qty</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600 dark:text-gray-300">Accepted Qty</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Unit</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600 dark:text-gray-300">Unit Price</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-600 dark:text-gray-300">Total Price</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-300">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                @forelse ($items as $item)
                    <tr
                        data-search="{{ mb_strtolower(trim(($item->item?->code ?? '').' '.($item->item?->name ?? ''))) }}"
                        x-show="! search || $el.dataset.search.includes(search.trim().toLowerCase())"
                    >
                        <td class="px-4 py-3 text-gray-950 dark:text-white">{{ $item->item ? trim($item->item->code.' - '.$item->item->name) : '-' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format((float) $item->qty_order, 2, '.', '') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format((float) $item->accepted_quantity, 2, '.', '') }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $item->unit_code }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->unit_price) ?? 'Rp 0,00' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->total_price) ?? 'Rp 0,00' }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $item->notes ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No items found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
