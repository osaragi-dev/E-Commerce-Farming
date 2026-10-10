<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Low Stock</x-slot>

        <x-slot name="description">
            Products that need replenishment.
        </x-slot>

        @if ($variants->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No low stock items.</p>
        @else
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($variants as $variant)
                    <a href="{{ $url }}" class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0 hover:bg-gray-50 dark:hover:bg-white/5">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $variant->product?->name }} - {{ $variant->name }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Min. Stock: {{ $variant->low_stock_threshold }} {{ $variant->unit?->code }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full bg-warning-50 px-2.5 py-1 text-sm font-semibold text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                            {{ \App\Support\Quantity::format($variant->stock_available) }} available
                        </span>
                    </a>
                @endforeach
            </div>

            <div class="mt-4 flex items-center justify-between gap-4 border-t border-gray-200 pt-4 dark:border-white/10">
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $total }} item(s) need attention</span>
                <a href="{{ $url }}" class="text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400">
                    View all
                </a>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
