@php
    $itemData = $record->items->map(fn ($item): array => [
        'name' => sprintf(
            '%s - %s',
            $item->productVariant?->product?->name ?? 'Unknown Product',
            $item->productVariant?->name ?? 'Unknown Variant',
        ),
        'qty_request' => \App\Support\Quantity::format($item->quantity),
        'qty_shipped' => $item->quantity_shipped === null ? null : \App\Support\Quantity::format($item->quantity_shipped),
        'qty_receive' => $item->quantity_received === null ? null : \App\Support\Quantity::format($item->quantity_received),
        'unit' => $item->unit ?? $item->productVariant?->unit?->code ?? $item->productVariant?->unit?->name ?? '-',
    ])->values();
@endphp

<div
    x-data="{
        items: @js($itemData),
        search: '',
        sortColumn: null,
        sortDirection: 'asc',
        page: 1,
        perPage: 10,
        get filteredItems() {
            const term = this.search.toLowerCase();
            return this.items.filter(item =>
                ! term || item.name.toLowerCase().includes(term) || item.unit.toLowerCase().includes(term) ||
                String(item.qty_request).includes(term) || String(item.qty_shipped ?? '').includes(term) ||
                String(item.qty_receive ?? '').includes(term)
            );
        },
        get sortedItems() {
            const items = [...this.filteredItems];
            if (! this.sortColumn) return items;
            return items.sort((a, b) => {
                let first = a[this.sortColumn] ?? '';
                let second = b[this.sortColumn] ?? '';
                if (['qty_request', 'qty_shipped', 'qty_receive'].includes(this.sortColumn)) {
                    first = Number(first || 0);
                    second = Number(second || 0);
                } else {
                    first = String(first).toLowerCase();
                    second = String(second).toLowerCase();
                }
                if (first === second) return 0;
                const result = first < second ? -1 : 1;
                return this.sortDirection === 'asc' ? result : -result;
            });
        },
        get paginatedItems() {
            return this.sortedItems.slice((this.page - 1) * this.perPage, this.page * this.perPage);
        },
        get totalPages() { return Math.max(1, Math.ceil(this.sortedItems.length / this.perPage)); },
        sort(column) {
            this.sortDirection = this.sortColumn === column && this.sortDirection === 'asc' ? 'desc' : 'asc';
            this.sortColumn = column;
        },
    }"
    x-init="$watch('search', () => page = 1); $watch('sortedItems', () => { if (page > totalPages) page = totalPages })"
    class="space-y-3"
>
    <div class="flex items-center justify-between gap-3">
        <div class="relative w-full max-w-sm">
            <input x-model.debounce.300ms="search" type="search" placeholder="Search items..." class="fi-input w-full" />
        </div>
        <div class="text-sm text-gray-500"><span x-text="sortedItems.length"></span> items</div>
    </div>
    <div class="overflow-x-auto">
        <table class="fi-table w-full text-left text-sm">
            <thead>
                <tr>
                    <th class="cursor-pointer" @click="sort('name')">Item</th>
                    <th class="cursor-pointer text-right" @click="sort('qty_request')">Qty Request</th>
                    <th class="cursor-pointer text-right" @click="sort('qty_shipped')">Qty Shipped</th>
                    <th class="cursor-pointer text-right" @click="sort('qty_receive')">Qty Receive</th>
                    <th class="cursor-pointer" @click="sort('unit')">Unit</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="item in paginatedItems" :key="item.name + item.qty_request">
                    <tr>
                        <td x-text="item.name"></td>
                        <td class="text-right" x-text="item.qty_request"></td>
                        <td class="text-right" x-text="item.qty_shipped ?? '-' "></td>
                        <td class="text-right" x-text="item.qty_receive ?? '-' "></td>
                        <td x-text="item.unit"></td>
                    </tr>
                </template>
                <tr x-show="paginatedItems.length === 0">
                    <td colspan="5" class="py-4 text-center text-gray-500">No items found.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div x-show="sortedItems.length > perPage" class="flex items-center justify-between text-sm">
        <span class="text-gray-500">Page <span x-text="page"></span> of <span x-text="totalPages"></span></span>
        <div class="flex gap-1">
            <button type="button" class="fi-btn fi-size-sm" @click="page = Math.max(1, page - 1)" :disabled="page === 1">Previous</button>
            <button type="button" class="fi-btn fi-size-sm" @click="page = Math.min(totalPages, page + 1)" :disabled="page === totalPages">Next</button>
        </div>
    </div>
</div>
