<div x-data="{ search: '', items: @js($items), get filtered() { const term = this.search.toLowerCase(); return this.items.filter(item => !term || [item.item, item.unit, item.type, item.notes].some(value => String(value ?? '').toLowerCase().includes(term))); } }" class="space-y-3">
    <div class="flex items-center justify-between gap-3">
        <label class="fi-input-wrp w-full max-w-sm">
            <span class="sr-only">Search items</span>
            <input class="fi-input w-full" type="search" x-model.debounce.300ms="search" placeholder="Search items...">
        </label>
        <span class="text-sm text-gray-500"><span x-text="filtered.length">{{ count($items) }}</span> items</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[680px] text-left text-sm">
            <thead class="bg-gray-50 text-xs font-medium dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2">Item</th><th class="px-3 py-2 text-right">System Qty</th><th class="px-3 py-2 text-right">Adjusted Qty</th><th class="px-3 py-2 text-right">Variance</th><th class="px-3 py-2">Unit</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <template x-for="item in filtered" :key="item.item">
                    <tr>
                        <td class="px-3 py-2" x-text="item.item"></td>
                        <td class="px-3 py-2 text-right tabular-nums" x-text="item.system_qty"></td>
                        <td class="px-3 py-2 text-right tabular-nums" x-text="item.adjusted_qty"></td>
                        <td class="px-3 py-2 text-right tabular-nums" :class="item.variance_value < 0 ? 'text-danger-600' : 'text-success-700'" x-text="item.variance"></td>
                        <td class="px-3 py-2" x-text="item.unit"></td>
                        <td class="px-3 py-2"><span class="rounded-md bg-gray-50 px-2 py-1 text-xs ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" x-text="item.type"></span></td>
                        <td class="px-3 py-2 text-gray-500" x-text="item.notes"></td>
                    </tr>
                </template>
                <tr x-show="filtered.length === 0"><td colspan="7" class="px-3 py-4 text-center text-gray-500">No items found.</td></tr>
            </tbody>
        </table>
    </div>
</div>
