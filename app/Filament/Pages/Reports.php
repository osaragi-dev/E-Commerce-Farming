<?php

namespace App\Filament\Pages;

use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Filament\Support\Rupiah;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ReportsQueryService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Reports';

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports';

    public string $startDate = '';

    public string $endDate = '';

    public string $revenueGrouping = 'daily';

    public string $productSalesSortColumn = 'revenue';

    public string $productSalesSortDirection = 'desc';

    /** @var list<string> */
    public array $productSalesSelectedRows = [];

    public string $paymentBreakdownSortColumn = 'amount';

    public string $paymentBreakdownSortDirection = 'desc';

    public string $revenueSummarySortColumn = 'period';

    public string $revenueSummarySortDirection = 'asc';

    /** @var array{start: string, end: string}|null */
    #[Locked]
    public ?array $salesPeriod = null;

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User && ($user->can('ViewAny:Order') || $user->can('ViewAny:Payment'));
    }

    public function mount(): void
    {
        $today = CarbonImmutable::now('Asia/Jakarta');
        $this->startDate = $today->toDateString();
        $this->endDate = $today->toDateString();
        $this->salesPeriod = ['start' => $this->startDate, 'end' => $this->endDate];
    }

    public function canViewOrders(): bool
    {
        return auth('web')->user()?->can('ViewAny:Order') ?? false;
    }

    public function canViewPayments(): bool
    {
        return auth('web')->user()?->can('ViewAny:Payment') ?? false;
    }

    public function canViewProductTables(): bool
    {
        return $this->canViewOrders() && $this->canViewPayments();
    }

    public function canViewRevenueSummary(): bool
    {
        return $this->canViewProductTables();
    }

    public function canExportProducts(): bool
    {
        return $this->canViewProductTables()
            && (auth('web')->user()?->can('Export:Order') ?? false);
    }

    /** @return list<array{label: string, value: string}> */
    public function summaryCards(): array
    {
        if ($this->salesPeriod === null) {
            return [];
        }

        $summary = app(ReportsQueryService::class)->summaryForPeriod(
            $this->salesPeriod['start'],
            $this->salesPeriod['end'],
            includeOrderMetrics: $this->canViewOrders(),
            includePaymentMetrics: $this->canViewPayments(),
        );
        $cards = [];

        if ($this->canViewPayments()) {
            $cards[] = ['label' => 'Total Sales', 'value' => Rupiah::formatWithPrefix($summary['total_sales']) ?? 'Rp 0,00'];
        }

        if ($this->canViewOrders()) {
            $cards[] = ['label' => 'Total Orders', 'value' => (string) $summary['total_orders']];
            $cards[] = ['label' => 'Done Orders', 'value' => (string) $summary['done_orders']];
            $cards[] = ['label' => 'Cancelled Orders', 'value' => (string) $summary['cancelled_orders']];
        }

        if ($this->canViewPayments()) {
            $cards[] = ['label' => 'Paid Orders', 'value' => (string) $summary['paid_orders']];
            $cards[] = ['label' => 'Unpaid / Waiting Confirmation Orders', 'value' => (string) $summary['unpaid_or_waiting_orders']];
            $cards[] = ['label' => 'Rejected Payment', 'value' => (string) $summary['rejected_orders']];
            $cards[] = ['label' => 'Void Payment', 'value' => (string) $summary['void_orders']];
        }

        return $cards;
    }

    /** @return Collection<int, array{payment_method: string, transaction_count: int, amount: float, percentage: float}> */
    public function paymentBreakdownRows(): Collection
    {
        if (! $this->canViewPayments() || $this->salesPeriod === null) {
            return collect();
        }

        return $this->sortRows(
            app(ReportsQueryService::class)->paymentBreakdownForPeriod(
                $this->salesPeriod['start'],
                $this->salesPeriod['end'],
            ),
            $this->paymentBreakdownSortColumn,
            $this->paymentBreakdownSortDirection,
        );
    }

    /** @return Collection<int, OrderItem> */
    public function productSalesRows(): Collection
    {
        if (! $this->canViewProductTables() || $this->salesPeriod === null) {
            return collect();
        }

        return $this->withProductSelectionKeys(app(ReportsQueryService::class)
            ->productSalesQuery(
                $this->salesPeriod['start'],
                $this->salesPeriod['end'],
                $this->productSalesSortColumn,
                $this->productSalesSortDirection,
            )
            ->get());
    }

    /** @return list<array{label: string, amount: float}|array{label: string, period: string, amount: float, orders: int, subtotal: float, discount: float, shipping_cost: float, total: float}> */
    public function revenueTrendRows(): array
    {
        if (! $this->canViewPayments() || $this->salesPeriod === null) {
            return [];
        }

        $period = $this->salesPeriod;
        $grouping = in_array($this->revenueGrouping, ['daily', 'weekly', 'monthly', 'yearly'], true)
            ? $this->revenueGrouping
            : 'daily';

        if (! $this->canViewRevenueSummary()) {
            return app(ReportsQueryService::class)->revenueTrend($period['start'], $period['end'], $grouping);
        }

        return app(ReportsQueryService::class)->revenueTrendSummary($period['start'], $period['end'], $grouping);
    }

    /** @return Collection<int, array{label: string, period: string, amount: float, orders: int, subtotal: float, discount: float, shipping_cost: float, total: float}> */
    public function revenueSummaryRows(): Collection
    {
        if (! $this->canViewRevenueSummary()) {
            return collect();
        }

        return $this->sortRows(
            collect($this->revenueTrendRows()),
            $this->revenueSummarySortColumn,
            $this->revenueSummarySortDirection,
        );
    }

    public function revenueTrendDetailUrl(): string
    {
        abort_unless($this->canViewRevenueSummary(), 403);
        abort_if($this->salesPeriod === null, 422);

        $period = $this->salesPeriod;

        return SalesRevenueTrendDetail::getUrl([
            'startDate' => $period['start'],
            'endDate' => $period['end'],
            'grouping' => $this->revenueGrouping,
        ]);
    }

    public function previewSalesReports(): void
    {
        abort_unless($this->canViewOrders() || $this->canViewPayments(), 403);

        $validated = Validator::make([
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], [
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate'],
        ])->validate();

        $this->salesPeriod = ['start' => $validated['startDate'], 'end' => $validated['endDate']];
        $this->productSalesSelectedRows = [];
        $this->dispatch('sales-report-filter-applied');
    }

    public function previewRevenueTrend(): void
    {
        abort_unless($this->canViewPayments(), 403);
        abort_if($this->salesPeriod === null, 422);

        if (! in_array($this->revenueGrouping, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
            $this->revenueGrouping = 'daily';
        }
    }

    public function sortTable(string $table, string $column): void
    {
        $columns = [
            'productSales' => ['menu', 'product_category', 'quantity_sold', 'revenue'],
            'paymentBreakdown' => ['payment_method', 'transaction_count', 'amount', 'percentage'],
            'revenueSummary' => ['period', 'orders', 'subtotal', 'discount', 'shipping_cost', 'total'],
        ];

        abort_unless(isset($columns[$table]) && in_array($column, $columns[$table], true), 422);

        $columnProperty = match ($table) {
            'productSales' => 'productSalesSortColumn',
            'paymentBreakdown' => 'paymentBreakdownSortColumn',
            default => 'revenueSummarySortColumn',
        };
        $directionProperty = match ($table) {
            'productSales' => 'productSalesSortDirection',
            'paymentBreakdown' => 'paymentBreakdownSortDirection',
            default => 'revenueSummarySortDirection',
        };

        $this->{$directionProperty} = $this->{$columnProperty} === $column
            ? ($this->{$directionProperty} === 'asc' ? 'desc' : 'asc')
            : 'asc';
        $this->{$columnProperty} = $column;
    }

    public function sortIndicator(string $table, string $column): string
    {
        [$sortColumn, $sortDirection] = match ($table) {
            'productSales' => [$this->productSalesSortColumn, $this->productSalesSortDirection],
            'paymentBreakdown' => [$this->paymentBreakdownSortColumn, $this->paymentBreakdownSortDirection],
            default => [$this->revenueSummarySortColumn, $this->revenueSummarySortDirection],
        };

        return $sortColumn !== $column ? '-' : ($sortDirection === 'asc' ? '^' : 'v');
    }

    public function exportProductSales(string $format): StreamedResponse
    {
        abort_unless($this->canExportProducts(), 403);
        abort_if($this->salesPeriod === null, 422);

        return $this->exportProductRows($this->productSalesRows(), $format);
    }

    public function exportSelectedProductSales(string $format): StreamedResponse
    {
        abort_unless($this->canExportProducts(), 403);
        abort_if($this->salesPeriod === null, 422);

        $records = $this->selectedProductRows($this->productSalesRows(), $this->productSalesSelectedRows);
        abort_if($records->isEmpty(), 422);

        return $this->exportProductRows($records, $format);
    }

    public function toggleAllProductSalesRows(): void
    {
        abort_unless($this->canExportProducts(), 403);

        $this->toggleAllProductRows($this->productSalesRows(), 'productSalesSelectedRows');
    }

    public function areAllProductSalesRowsSelected(): bool
    {
        return $this->areAllProductRowsSelected($this->productSalesRows(), $this->productSalesSelectedRows);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function sortRows(Collection $rows, string $column, string $direction): Collection
    {
        $direction = $direction === 'asc' ? 1 : -1;

        return $rows->sort(function (array $left, array $right) use ($column, $direction): int {
            $leftValue = $left[$column] ?? null;
            $rightValue = $right[$column] ?? null;
            $comparison = is_numeric($leftValue) && is_numeric($rightValue)
                ? ((float) $leftValue <=> (float) $rightValue)
                : strnatcasecmp((string) $leftValue, (string) $rightValue);

            return $comparison * $direction;
        })->values();
    }

    /** @param Collection<int, OrderItem> $records */
    private function exportProductRows(Collection $records, string $format): StreamedResponse
    {
        $method = match (strtolower($format)) {
            'xlsx', 'excel' => 'downloadExcel',
            'csv' => 'downloadCsv',
            'pdf' => 'downloadPdf',
            default => abort(422),
        };
        $definition = new TableExportDefinition(
            title: 'Product Sales',
            columns: [
                'menu' => 'Product',
                'product_category' => 'Product Categories',
                'quantity_sold' => 'Qty Sold',
                'revenue' => 'Amount',
            ],
            monetary: ['revenue'],
        );

        return app(TableExportService::class)->{$method}($records, Order::class, $definition);
    }

    /** @param Collection<int, OrderItem> $records
     * @return Collection<int, OrderItem>
     */
    private function withProductSelectionKeys(Collection $records): Collection
    {
        return $records->each(function (OrderItem $item): void {
            $item->setAttribute('report_row_key', hash_hmac(
                'sha256',
                implode("\0", [
                    (string) $item->report_product_id,
                    (string) $item->menu,
                    (string) $item->product_category,
                ]),
                (string) config('app.key'),
            ));
        });
    }

    /** @param list<string> $selectedRows */
    private function toggleAllProductRows(Collection $records, string $selectionProperty): void
    {
        $rowKeys = $this->productRowKeys($records);
        $selectedKeys = $this->validProductRowKeys($this->{$selectionProperty});

        $this->{$selectionProperty} = $rowKeys !== [] && array_diff($rowKeys, $selectedKeys) === []
            ? []
            : $rowKeys;
    }

    /** @param list<string> $selectedRows */
    private function areAllProductRowsSelected(Collection $records, array $selectedRows): bool
    {
        $rowKeys = $this->productRowKeys($records);

        return $rowKeys !== [] && array_diff($rowKeys, $this->validProductRowKeys($selectedRows)) === [];
    }

    /** @param Collection<int, OrderItem> $records
     * @return list<string>
     */
    private function productRowKeys(Collection $records): array
    {
        return $records->pluck('report_row_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, OrderItem>  $records
     * @param  list<string>  $selectedRows
     * @return Collection<int, OrderItem>
     */
    private function selectedProductRows(Collection $records, array $selectedRows): Collection
    {
        $selectedKeys = array_fill_keys($this->validProductRowKeys($selectedRows), true);

        return $records
            ->filter(fn (OrderItem $item): bool => isset($selectedKeys[$item->report_row_key]))
            ->values();
    }

    /** @param list<string> $selectedRows
     * @return list<string>
     */
    private function validProductRowKeys(array $selectedRows): array
    {
        return collect($selectedRows)
            ->filter(fn (mixed $key): bool => is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1)
            ->unique()
            ->values()
            ->all();
    }
}
