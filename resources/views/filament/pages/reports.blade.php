<x-filament-panels::page>
    @php
        $canViewOrders = $this->canViewOrders();
        $canViewPayments = $this->canViewPayments();
        $canViewProductTables = $this->canViewProductTables();
        $summaryCards = $this->summaryCards();
    @endphp

    <div class="space-y-8">
        @if ($canViewOrders || $canViewPayments)
            <section class="space-y-6" x-data="{ filtersOpen: false }" x-on:sales-report-filter-applied.window="filtersOpen = false">
                <div class="grid justify-items-start gap-3">
                    <div class="relative">
                        <x-filament::button
                            color="success"
                            icon="heroicon-o-funnel"
                            x-on:click="filtersOpen = ! filtersOpen"
                            x-bind:aria-expanded="filtersOpen"
                            aria-label="Reports Filters"
                        >
                            Filters
                        </x-filament::button>

                        <div
                            x-cloak
                            x-show="filtersOpen"
                            x-transition.origin.top.left
                            x-on:click.outside="filtersOpen = false"
                            class="absolute left-0 z-30 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-4 shadow-lg dark:border-gray-700 dark:bg-gray-900 sm:w-[34rem]"
                        >
                            <form wire:submit="previewSalesReports" class="grid gap-4 sm:grid-cols-2">
                                <label class="grid gap-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Start Date
                                    <input type="date" wire:model="startDate" class="min-w-0 rounded-lg border-gray-300 bg-white text-base font-normal normal-case tracking-normal text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                                    @error('startDate') <span class="text-xs font-normal normal-case tracking-normal text-danger-600">{{ $message }}</span> @enderror
                                </label>
                                <label class="grid gap-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    End Date
                                    <input type="date" wire:model="endDate" class="min-w-0 rounded-lg border-gray-300 bg-white text-base font-normal normal-case tracking-normal text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                                    @error('endDate') <span class="text-xs font-normal normal-case tracking-normal text-danger-600">{{ $message }}</span> @enderror
                                </label>
                                <div class="flex items-center justify-between gap-3 sm:col-span-2">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Choose the reporting date range.</p>
                                    <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="previewSalesReports">
                                        Apply
                                    </x-filament::button>
                                </div>
                            </form>
                        </div>
                    </div>

                    @if ($this->salesPeriod !== null)
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Applied range:
                            {{ \Carbon\CarbonImmutable::parse($this->salesPeriod['start'], 'Asia/Jakarta')->format('d M Y') }}
                            &ndash;
                            {{ \Carbon\CarbonImmutable::parse($this->salesPeriod['end'], 'Asia/Jakarta')->format('d M Y') }}
                        </p>
                    @endif
                </div>
            </section>

            @if ($this->salesPeriod !== null)
                <x-filament::section heading="Sales Summary" description="Order and payment performance for the selected date range.">
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($summaryCards as $card)
                            <article wire:key="summary-card-{{ Illuminate\Support\Str::slug($card['label']) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                                <p class="text-sm font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                                <p class="mt-3 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $card['value'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @endif

        @if ($canViewPayments)
            <section class="space-y-6">
                <x-filament::section
                    heading="Amount Trend"
                    description="Payment amount trend over the selected period."
                >
                    @if ($canViewPayments)
                        @if ($this->salesPeriod === null)
                            <div class="mt-6 rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                Choose a start and end date, then click Apply to load reports.
                            </div>
                        @else
                            @php
                                $trendRows = $this->revenueTrendRows();
                                $maxRevenue = max([1.0, ...array_column($trendRows, 'amount')]);
                                $maxOrders = max([1, ...array_column($trendRows, 'orders')]);
                                $hasTrendData = collect($trendRows)->contains(fn (array $row): bool => (float) $row['amount'] > 0 || (int) ($row['orders'] ?? 0) > 0);
                                $chartWidth = max(960, min(count($trendRows) * 48 + 240, 2600));
                                $chartLeft = 150;
                                $chartRight = $chartWidth - 72;
                                $chartTop = 38;
                                $chartBottom = 276;
                                $plotStep = ($chartRight - $chartLeft) / max(1, count($trendRows));
                                $chartLabelInterval = max(1, (int) ceil(count($trendRows) / 14));
                                $chartPoints = collect($trendRows)->map(function (array $row, int $index) use ($maxRevenue, $maxOrders, $chartLeft, $chartRight, $chartTop, $chartBottom, $plotStep, $canViewOrders): array {
                                    $x = $chartLeft + ($plotStep * $index) + ($plotStep / 2);
                                    $orders = $row['orders'] ?? 0;
                                    $revenueY = $chartBottom - (($row['amount'] / $maxRevenue) * ($chartBottom - $chartTop));
                                    $ordersY = $chartBottom - (($orders / $maxOrders) * ($chartBottom - $chartTop));
                                    $revenueLabel = \App\Filament\Support\Rupiah::formatWithPrefix($row['amount']) ?? 'Rp 0,00';
                                    $tooltip = $row['label'].' | Amount: '.$revenueLabel;

                                    if ($canViewOrders) {
                                        $tooltip .= ' | Orders: '.number_format($orders);
                                    }

                                    $tooltipAnchorY = $canViewOrders ? min($revenueY, $ordersY) : $revenueY;

                                    return [
                                        'x' => $x,
                                        'revenue_y' => $revenueY,
                                        'orders_y' => $ordersY,
                                        'bar_width' => min(58, $plotStep * 0.56),
                                        'hit_width' => $plotStep,
                                        'tooltip_x' => min($chartRight - 252, max($chartLeft, $x + 18)),
                                        'tooltip_y' => max($chartTop + 8, $tooltipAnchorY - 118),
                                        'label' => $row['label'],
                                        'amount' => $row['amount'],
                                        'revenue_label' => $revenueLabel,
                                        'orders' => $orders,
                                        'tooltip' => $tooltip,
                                    ];
                                });
                                $ordersLinePoints = $chartPoints->map(fn (array $point): string => $point['x'] . ',' . $point['orders_y'])->implode(' ');
                            @endphp

                            <div class="mt-6 space-y-5">
                                <fieldset class="grid w-full grid-cols-2 gap-2 sm:grid-cols-4">
                                    <legend class="sr-only">Amount grouping</legend>
                                    @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                                        <label class="block cursor-pointer">
                                            <input type="radio" wire:model.live="revenueGrouping" value="{{ $value }}" class="peer sr-only">
                                            <span class="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-800 transition hover:bg-gray-50 peer-checked:border-success-600 peer-checked:bg-success-600 peer-checked:text-white dark:border-gray-700 dark:text-gray-100 dark:hover:bg-gray-800 dark:peer-checked:border-success-600 dark:peer-checked:bg-success-600">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </fieldset>

                                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-gradient-to-br from-white via-white to-emerald-50/60 shadow-sm dark:border-gray-800 dark:from-gray-900 dark:via-gray-900 dark:to-gray-950">
                                    @if ($chartPoints->isNotEmpty() && $hasTrendData)
                                        <div class="overflow-x-auto p-4 sm:p-6">
                                            <svg viewBox="0 0 {{ $chartWidth }} 350" role="group" aria-label="{{ $canViewOrders ? 'Amount and Orders by period' : 'Amount by period' }}" class="block h-[22rem] min-w-[760px]" style="width: {{ $chartWidth }}px">
                                            <defs>
                                                <linearGradient id="reports-revenue-gradient" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="#34785f" />
                                                    <stop offset="100%" stop-color="#1f4d3d" />
                                                </linearGradient>
                                            </defs>
                                            <rect x="{{ $chartLeft }}" y="{{ $chartTop }}" width="{{ $chartRight - $chartLeft }}" height="{{ $chartBottom - $chartTop }}" rx="12" class="fill-gray-50 dark:fill-gray-950/60" />
                                            <text x="{{ $chartLeft }}" y="22" class="fill-gray-500 text-[12px] font-medium">Amount (Rp)</text>
                                            @if ($canViewOrders)
                                                <text x="{{ $chartRight }}" y="22" text-anchor="end" class="fill-gray-500 text-[12px] font-medium">Orders</text>
                                            @endif
                                            @foreach (range(0, 4) as $tick)
                                                @php
                                                    $tickY = $chartTop + (($chartBottom - $chartTop) * $tick / 4);
                                                    $tickAmount = round($maxRevenue * (1 - ($tick / 4)));
                                                    $tickOrders = round($maxOrders * (1 - ($tick / 4)));
                                                @endphp
                                                <line x1="{{ $chartLeft }}" y1="{{ $tickY }}" x2="{{ $chartRight }}" y2="{{ $tickY }}" stroke="currentColor" stroke-dasharray="4 7" class="text-gray-200 dark:text-gray-700" />
                                                <text x="{{ $chartLeft - 14 }}" y="{{ $tickY + 4 }}" text-anchor="end" class="fill-gray-500 text-[12px] font-medium">{{ \App\Filament\Support\Rupiah::formatWithPrefix($tickAmount) }}</text>
                                                @if ($canViewOrders)
                                                    <text x="{{ $chartRight + 14 }}" y="{{ $tickY + 4 }}" text-anchor="start" class="fill-gray-500 text-[12px] font-medium">{{ number_format($tickOrders) }}</text>
                                                @endif
                                            @endforeach

                                            <line x1="{{ $chartLeft }}" y1="{{ $chartTop }}" x2="{{ $chartLeft }}" y2="{{ $chartBottom }}" stroke="currentColor" class="text-gray-300 dark:text-gray-600" />
                                            <line x1="{{ $chartLeft }}" y1="{{ $chartBottom }}" x2="{{ $chartRight }}" y2="{{ $chartBottom }}" stroke="currentColor" class="text-gray-300 dark:text-gray-600" />
                                            @if ($canViewOrders)
                                                <line x1="{{ $chartRight }}" y1="{{ $chartTop }}" x2="{{ $chartRight }}" y2="{{ $chartBottom }}" stroke="currentColor" class="text-gray-300 dark:text-gray-600" />
                                            @endif
                                            @foreach ($chartPoints as $point)
                                                <rect x="{{ $point['x'] - ($point['bar_width'] / 2) }}" y="{{ $point['revenue_y'] }}" width="{{ $point['bar_width'] }}" height="{{ max(0, $chartBottom - $point['revenue_y']) }}" rx="7" fill="url(#reports-revenue-gradient)" class="transition-opacity hover:opacity-80">
                                                </rect>
                                                @if ($loop->index % $chartLabelInterval === 0 || $loop->last)
                                                    <text x="{{ $point['x'] }}" y="{{ $chartBottom + 29 }}" text-anchor="middle" class="pointer-events-none fill-gray-500 text-[12px] font-medium">{{ $point['label'] }}</text>
                                                @endif
                                            @endforeach
                                            @if ($canViewOrders && $chartPoints->count() > 1)
                                                <polyline points="{{ $ordersLinePoints }}" fill="none" stroke="#d07a24" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                                            @endif
                                            @foreach ($chartPoints as $point)
                                                <g tabindex="0" role="img" aria-label="{{ $point['tooltip'] }}" class="group cursor-default">
                                                    <rect x="{{ $point['x'] - ($point['hit_width'] / 2) }}" y="{{ $chartTop }}" width="{{ $point['hit_width'] }}" height="{{ $chartBottom - $chartTop }}" fill="transparent" />
                                                    @if ($canViewOrders)
                                                        <circle cx="{{ $point['x'] }}" cy="{{ $point['orders_y'] }}" r="6" fill="white" stroke="#d07a24" stroke-width="3" />
                                                    @endif
                                                    <foreignObject x="{{ $point['tooltip_x'] }}" y="{{ $point['tooltip_y'] }}" width="244" height="112" class="pointer-events-none opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100">
                                                        <div xmlns="http://www.w3.org/1999/xhtml" role="tooltip" class="rounded-xl border border-white/10 bg-gray-950/95 p-3.5 text-white shadow-2xl ring-1 ring-black/10 backdrop-blur-sm">
                                                            <p class="truncate text-xs font-semibold uppercase tracking-wide text-gray-300">{{ $point['label'] }}</p>
                                                            <div class="mt-3 grid gap-2.5 text-sm">
                                                                <div class="flex items-center justify-between gap-4">
                                                                    <span class="inline-flex items-center gap-2 text-gray-300"><span class="h-2.5 w-2.5 rounded-sm bg-[#34785f]"></span>Amount</span>
                                                                    <span class="font-semibold tabular-nums text-white">{{ $point['revenue_label'] }}</span>
                                                                </div>
                                                                @if ($canViewOrders)
                                                                    <div class="flex items-center justify-between gap-4">
                                                                        <span class="inline-flex items-center gap-2 text-gray-300"><span class="h-0.5 w-3 bg-[#d07a24]"></span>Orders</span>
                                                                        <span class="font-semibold tabular-nums text-white">{{ number_format($point['orders']) }}</span>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </foreignObject>
                                                </g>
                                            @endforeach
                                            </svg>
                                        </div>
                                    @else
                                        <div class="flex h-64 items-center justify-center p-4 text-center text-sm text-gray-500 dark:text-gray-400">No confirmed payments or orders in the selected range.</div>
                                    @endif
                                    <div class="flex flex-wrap justify-center gap-x-8 gap-y-3 border-t border-gray-200 px-4 py-4 text-sm text-gray-700 dark:border-gray-800 dark:text-gray-300">
                                        <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-sm bg-[#1f4d3d]"></span>Amount</span>
                                        @if ($canViewOrders)
                                            <span class="inline-flex items-center gap-2"><span class="relative h-0.5 w-4 bg-[#d07a24]"><span class="absolute -top-1 left-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-[#d07a24] dark:border-gray-900"></span></span>Orders</span>
                                        @endif
                                    </div>
                                </div>

                                @if ($this->canViewRevenueSummary())
                                    @php
                                        $summaryRows = $this->revenueSummaryRows();
                                    @endphp
                                    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                                        <div class="overflow-x-auto">
                                            <table class="w-full min-w-[60rem] table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-800">
                                                <colgroup>
                                                    <col class="w-[18%]">
                                                    <col class="w-[10%]">
                                                    <col class="w-[17%]">
                                                    <col class="w-[15%]">
                                                    <col class="w-[20%]">
                                                    <col class="w-[20%]">
                                                </colgroup>
                                                <thead class="bg-gray-50 dark:bg-gray-950">
                                                    <tr>
                                                        @foreach ([['period', 'Period', 'left'], ['orders', 'Orders', 'right'], ['subtotal', 'Subtotal', 'right'], ['discount', 'Discount', 'right'], ['shipping_cost', 'Shipping Cost', 'right'], ['total', 'Total', 'right']] as [$column, $label, $alignment])
                                                            @php
                                                                $sortIndicator = $this->sortIndicator('revenueSummary', $column);
                                                            @endphp
                                                            <th scope="col" class="whitespace-nowrap px-4 py-3 align-middle font-medium text-gray-900 dark:text-gray-100 {{ $alignment === 'right' ? 'text-right' : 'text-left' }}">
                                                                <button type="button" wire:click="sortTable('revenueSummary', '{{ $column }}')" class="inline-flex items-center gap-2 whitespace-nowrap hover:text-primary-700 dark:hover:text-primary-300 {{ $alignment === 'right' ? 'w-full justify-end' : '' }}">
                                                                    {{ $label }}
                                                                    <x-filament::icon :icon="$sortIndicator === '-' ? 'heroicon-o-chevron-up-down' : ($sortIndicator === '^' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down')" class="h-4 w-4 text-gray-400" />
                                                                </button>
                                                            </th>
                                                        @endforeach
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                    @forelse ($summaryRows as $row)
                                                        <tr wire:key="summary-period-{{ $loop->index }}" class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/50">
                                                            <td class="whitespace-nowrap px-4 py-3 align-middle font-medium text-gray-950 dark:text-white">{{ $row['period'] }}</td>
                                                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['orders']) }}</td>
                                                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle tabular-nums text-gray-600 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['subtotal']) }}</td>
                                                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle tabular-nums text-gray-600 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['discount']) }}</td>
                                                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle tabular-nums text-gray-600 dark:text-gray-300">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['shipping_cost']) }}</td>
                                                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['total']) }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">No report periods found.</td></tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="flex justify-end border-t border-gray-200 p-4 dark:border-gray-800">
                                            <a href="{{ $this->revenueTrendDetailUrl() }}" class="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-gray-800">
                                                View all
                                                <x-filament::icon icon="heroicon-o-arrow-right" class="h-4 w-4" />
                                            </a>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endif
                </x-filament::section>
            </section>
        @endif

        @if ($canViewPayments)
            @php
                $paymentRows = $this->paymentBreakdownRows();
            @endphp

            <x-filament::section
                heading="Payment Breakdown"
                description="Payment method distribution and transaction summary."
            >
                <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[48rem] table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-800">
                            <colgroup>
                                <col class="w-[32%]">
                                <col class="w-[24%]">
                                <col class="w-[26%]">
                                <col class="w-[18%]">
                            </colgroup>
                            <thead class="bg-gray-50 dark:bg-gray-950">
                                <tr>
                                    @foreach ([['payment_method', 'Payment Method', 'left'], ['transaction_count', 'Transaction Count', 'right'], ['amount', 'Amount', 'right'], ['percentage', 'Percentage', 'right']] as [$column, $label, $alignment])
                                        @php
                                            $sortIndicator = $this->sortIndicator('paymentBreakdown', $column);
                                        @endphp
                                        <th scope="col" class="whitespace-nowrap px-4 py-3 align-middle font-medium text-gray-900 dark:text-gray-100 {{ $alignment === 'right' ? 'text-right' : 'text-left' }}">
                                            <button type="button" wire:click="sortTable('paymentBreakdown', '{{ $column }}')" class="inline-flex items-center gap-2 whitespace-nowrap hover:text-primary-700 dark:hover:text-primary-300 {{ $alignment === 'right' ? 'w-full justify-end' : '' }}">
                                                {{ $label }}
                                                <x-filament::icon :icon="$sortIndicator === '-' ? 'heroicon-o-chevron-up-down' : ($sortIndicator === '^' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down')" class="h-4 w-4 text-gray-400" />
                                            </button>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                @forelse ($paymentRows as $row)
                                    <tr wire:key="payment-breakdown-{{ Illuminate\Support\Str::slug($row['payment_method']) }}" class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/50">
                            <td class="whitespace-nowrap px-4 py-3 align-middle font-medium text-gray-950 dark:text-white">{{ $row['payment_method'] }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['transaction_count']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Filament\Support\Rupiah::formatWithPrefix($row['amount']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right align-middle tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['percentage'], 2) }}%</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="h-56 px-4 py-10 text-center">
                                            <div class="flex flex-col items-center justify-center gap-4 text-gray-600 dark:text-gray-300">
                                                <span class="flex size-14 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-o-x-mark" class="size-7" />
                                                </span>
                                                <span class="text-base font-semibold">No confirmed payments in this date range</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-filament::section>
        @endif

        @if ($this->salesPeriod !== null && $canViewProductTables)
            @php
                $productSales = $this->productSalesRows();
            @endphp

            <x-filament::section
                heading="Product Sales"
                description="Product sales performance for the selected date range."
            >
                <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                    @if ($this->canExportProducts())
                        <div class="flex flex-wrap justify-end gap-2 border-b border-gray-200 p-4 dark:border-gray-800">
                            <x-filament::dropdown placement="bottom-end">
                                <x-slot name="trigger">
                                    <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray">
                                        Export
                                    </x-filament::button>
                                </x-slot>
                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item color="success" icon="heroicon-o-table-cells" wire:click="exportProductSales('excel')">Export Excel</x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item color="info" icon="heroicon-o-document-text" wire:click="exportProductSales('csv')">Export CSV</x-filament::dropdown.list.item>
                                    <x-filament::dropdown.list.item color="danger" icon="heroicon-o-document" wire:click="exportProductSales('pdf')">Export PDF</x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>

                            @if ($this->productSalesSelectedRows !== [])
                                <x-filament::dropdown placement="bottom-end">
                                    <x-slot name="trigger">
                                        <x-filament::button color="gray" icon="heroicon-o-squares-plus">
                                            Bulk Actions
                                        </x-filament::button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        <x-filament::dropdown.list.item color="success" icon="heroicon-o-table-cells" wire:click="exportSelectedProductSales('excel')">Export Selected Excel</x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item color="info" icon="heroicon-o-document-text" wire:click="exportSelectedProductSales('csv')">Export Selected CSV</x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item color="danger" icon="heroicon-o-document" wire:click="exportSelectedProductSales('pdf')">Export Selected PDF</x-filament::dropdown.list.item>
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            @else
                                <x-filament::button color="gray" icon="heroicon-o-squares-plus" disabled>
                                    Bulk Actions
                                </x-filament::button>
                            @endif
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[60rem] table-fixed divide-y divide-gray-200 text-sm dark:divide-gray-800">
                            <colgroup>
                                @if ($this->canExportProducts())
                                    <col class="w-12">
                                    <col class="w-[32%]">
                                    <col class="w-[28%]">
                                    <col class="w-[17%]">
                                    <col class="w-[18%]">
                                @else
                                    <col class="w-[35%]">
                                    <col class="w-[30%]">
                                    <col class="w-[15%]">
                                    <col class="w-[20%]">
                                @endif
                            </colgroup>
                            <thead class="bg-gray-50 dark:bg-gray-950">
                                <tr>
                                    @if ($this->canExportProducts())
                                        <th scope="col" class="w-12 px-4 py-3 text-center align-middle">
                                            <input type="checkbox" aria-label="Select all product sales" @checked($this->areAllProductSalesRowsSelected()) wire:click="toggleAllProductSalesRows" class="size-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-900">
                                        </th>
                                    @endif
                                    @foreach ([['menu', 'Product', 'left'], ['product_category', 'Product Categories', 'left'], ['quantity_sold', 'Qty Sold', 'right'], ['revenue', 'Amount', 'right']] as [$column, $label, $alignment])
                                        @php
                                            $sortIndicator = $this->sortIndicator('productSales', $column);
                                        @endphp
                                        <th scope="col" class="whitespace-nowrap px-4 py-3 align-middle font-medium text-gray-900 dark:text-gray-100 {{ $alignment === 'right' ? 'text-right' : 'text-left' }}">
                                            <button type="button" wire:click="sortTable('productSales', '{{ $column }}')" class="inline-flex items-center gap-2 whitespace-nowrap hover:text-primary-700 dark:hover:text-primary-300 {{ $alignment === 'right' ? 'w-full justify-end' : '' }}">
                                                {{ $label }}
                                                <x-filament::icon :icon="$sortIndicator === '-' ? 'heroicon-o-chevron-up-down' : ($sortIndicator === '^' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down')" class="h-4 w-4 text-gray-400" />
                                            </button>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                @forelse ($productSales as $item)
                                    <tr wire:key="product-sales-{{ $item->report_row_key }}" class="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/50">
                                        @if ($this->canExportProducts())
                                            <td class="w-12 px-4 py-3 text-center align-middle">
                                                <input type="checkbox" aria-label="Select {{ $item->menu }}" value="{{ $item->report_row_key }}" wire:model.live="productSalesSelectedRows" class="size-4 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 dark:border-gray-700 dark:bg-gray-900">
                                            </td>
                                        @endif
                                        <td class="break-words px-4 py-3 align-middle font-medium text-gray-950 dark:text-white">{{ $item->menu }}</td>
                                        <td class="break-words px-4 py-3 align-middle text-gray-600 dark:text-gray-300">{{ $item->product_category ?: '-' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right align-middle tabular-nums text-gray-600 dark:text-gray-300">{{ \App\Support\Quantity::format($item->quantity_sold) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right align-middle font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->revenue) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $this->canExportProducts() ? 5 : 4 }}" class="h-64 px-4 py-10 text-center">
                                            <div class="flex flex-col items-center justify-center gap-5 text-gray-600 dark:text-gray-300">
                                                <span class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-o-x-mark" class="h-8 w-8" />
                                                </span>
                                                <span class="text-lg font-semibold">No product sales in this date range</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
