<x-filament-panels::page>
    @php
        $rows = $this->periodRows();
        $canExport = $this->canExport();
        $columns = [
            ['period', 'Period', 'left'],
            ['orders', 'Orders', 'right'],
            ['subtotal', 'Subtotal', 'right'],
            ['discount', 'Discount', 'right'],
            ['shipping_cost', 'Shipping Cost', 'right'],
            ['total', 'Total', 'right'],
        ];
        $groupingLabel = [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            'custom' => 'Custom Range',
        ][$grouping] ?? 'Daily';
    @endphp

    <div class="space-y-6">
        <x-filament::section
            heading="Revenue Trend Detail"
            :description="$groupingLabel . ' periods from ' . \Carbon\CarbonImmutable::parse($startDate, 'Asia/Jakarta')->format('d M Y') . ' to ' . \Carbon\CarbonImmutable::parse($endDate, 'Asia/Jakarta')->format('d M Y')"
        >
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div class="w-full sm:max-w-md">
                    <label for="period-search" class="sr-only">Search Period</label>
                    <div class="relative">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="pointer-events-none absolute left-4 top-1/2 size-6 -translate-y-1/2 text-gray-400 dark:text-gray-500" />
                        <input
                            id="period-search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search period..."
                            class="h-14 w-full rounded-2xl border border-gray-200 bg-white pl-12 pr-4 text-base text-gray-950 shadow-sm placeholder:text-gray-400 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10 dark:border-gray-700 dark:bg-gray-950 dark:text-white dark:placeholder:text-gray-500"
                        >
                    </div>
                </div>

                @if ($canExport)
                    <div class="flex flex-wrap gap-2 sm:ml-auto">
                        <x-filament::dropdown placement="bottom-end">
                            <x-slot name="trigger">
                                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray">
                                    Export
                                </x-filament::button>
                            </x-slot>
                            <x-filament::dropdown.list>
                                <x-filament::dropdown.list.item color="success" icon="heroicon-o-table-cells" wire:click="export('excel')">Export Excel</x-filament::dropdown.list.item>
                                <x-filament::dropdown.list.item color="info" icon="heroicon-o-document-text" wire:click="export('csv')">Export CSV</x-filament::dropdown.list.item>
                                <x-filament::dropdown.list.item color="danger" icon="heroicon-o-document" wire:click="export('pdf')">Export PDF</x-filament::dropdown.list.item>
                            </x-filament::dropdown.list>
                        </x-filament::dropdown>

                        @if ($this->selectedRows !== [])
                            <x-filament::dropdown placement="bottom-end">
                                <x-slot name="trigger">
                                    <x-filament::button color="gray" icon="heroicon-o-squares-plus">
                                        Bulk Actions
                                    </x-filament::button>
                                </x-slot>
                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item color="success" icon="heroicon-o-table-cells" wire:click="exportSelected('excel')">Export Selected Excel</x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item color="info" icon="heroicon-o-document-text" wire:click="exportSelected('csv')">Export Selected CSV</x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item color="danger" icon="heroicon-o-document" wire:click="exportSelected('pdf')">Export Selected PDF</x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>
                        @else
                            <x-filament::button color="gray" icon="heroicon-o-squares-plus" disabled>
                                Bulk Actions
                            </x-filament::button>
                        @endif
                    </div>
                @endif
            </div>

            <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[60rem] table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <colgroup>
                            @if ($canExport)
                                <col class="w-12">
                            @endif
                            <col class="w-[18%]">
                            <col class="w-[10%]">
                            <col class="w-[17%]">
                            <col class="w-[15%]">
                            <col class="w-[20%]">
                            <col class="w-[20%]">
                        </colgroup>
                        <thead class="bg-gray-50 dark:bg-gray-950">
                            <tr>
                                @if ($canExport)
                                    <th scope="col" class="w-12 px-4 py-3 text-center">
                                        <input type="checkbox" aria-label="Select all revenue trend periods" @checked($this->areAllRowsSelected()) wire:click="toggleAllRows" class="size-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-900">
                                    </th>
                                @endif
                                @foreach ($columns as [$column, $label, $alignment])
                                    @php($sortIndicator = $this->sortIndicator($column))
                                    <th scope="col" class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100 {{ $alignment === 'right' ? 'text-right' : 'text-left' }}">
                                        <button type="button" wire:click="sortBy('{{ $column }}')" class="inline-flex items-center gap-2 hover:text-primary-700 dark:hover:text-primary-300 {{ $alignment === 'right' ? 'w-full justify-end' : '' }}">
                                            {{ $label }}
                                            <x-filament::icon :icon="$sortIndicator === '-' ? 'heroicon-o-chevron-up-down' : ($sortIndicator === '^' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down')" class="h-4 w-4 text-gray-400" />
                                        </button>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                            @forelse ($rows as $row)
                                <tr wire:key="trend-period-{{ $row['report_row_key'] }}" class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/60">
                                    @if ($canExport)
                                        <td class="px-4 py-3 text-center">
                                            <input type="checkbox" aria-label="Select {{ $row['period'] }}" value="{{ $row['report_row_key'] }}" wire:model.live="selectedRows" class="size-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-900">
                                        </td>
                                    @endif
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['period'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['orders']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['subtotal']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['discount']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['shipping_cost']) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['total']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $canExport ? 7 : 6 }}" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">No periods match your search.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
