<?php

namespace App\Filament\Pages;

use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Models\Order;
use App\Models\User;
use App\Services\ReportsQueryService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesRevenueTrendDetail extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Sales Revenue Trend Detail';

    protected static ?string $slug = 'sales-revenue-trend-detail';

    protected string $view = 'filament.pages.sales-revenue-trend-detail';

    #[Locked]
    public string $startDate = '';

    #[Locked]
    public string $endDate = '';

    #[Locked]
    public string $grouping = 'daily';

    public string $search = '';

    public string $sortColumn = 'period';

    public string $sortDirection = 'asc';

    /** @var list<string> */
    public array $selectedRows = [];

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User
            && $user->can('ViewAny:Order')
            && $user->can('ViewAny:Payment');
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            Reports::getUrl() => 'Reports',
            'Sales Revenue Trend Detail',
        ];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $validated = Validator::make(request()->query(), [
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'grouping' => ['required', 'in:daily,weekly,monthly,yearly,custom'],
        ])->validate();

        $this->startDate = $validated['startDate'];
        $this->endDate = $validated['endDate'];
        $this->grouping = $validated['grouping'];
    }

    /** @return Collection<int, array{label: string, period: string, amount: float, orders: int, subtotal: float, discount: float, shipping_cost: float, total: float, report_row_key: string}> */
    public function periodRows(): Collection
    {
        abort_unless(static::canAccess(), 403);

        $rows = collect(app(ReportsQueryService::class)->revenueTrendSummary(
            $this->startDate,
            $this->endDate,
            $this->grouping === 'custom' ? 'daily' : $this->grouping,
        ));
        $search = trim($this->search);

        return $rows
            ->when($search !== '', fn (Collection $items): Collection => $items->filter(
                fn (array $row): bool => mb_stripos($row['period'], $search) !== false,
            ))
            ->sort(function (array $left, array $right): int {
                $leftValue = $left[$this->sortColumn] ?? null;
                $rightValue = $right[$this->sortColumn] ?? null;
                $comparison = is_numeric($leftValue) && is_numeric($rightValue)
                    ? ((float) $leftValue <=> (float) $rightValue)
                    : strnatcasecmp((string) $leftValue, (string) $rightValue);

                return $comparison * ($this->sortDirection === 'asc' ? 1 : -1);
            })
            ->values()
            ->map(function (array $row): array {
                $row['report_row_key'] = hash_hmac(
                    'sha256',
                    implode("\0", [$this->startDate, $this->endDate, $this->grouping, $row['period']]),
                    (string) config('app.key'),
                );

                return $row;
            });
    }

    public function updatedSearch(): void
    {
        $activeKeys = $this->periodRowKeys();
        $this->selectedRows = array_values(array_intersect($this->validSelectedRows(), $activeKeys));
    }

    public function sortBy(string $column): void
    {
        $columns = ['period', 'orders', 'subtotal', 'discount', 'shipping_cost', 'total'];
        abort_unless(in_array($column, $columns, true), 422);

        $this->sortDirection = $this->sortColumn === $column
            ? ($this->sortDirection === 'asc' ? 'desc' : 'asc')
            : 'asc';
        $this->sortColumn = $column;
    }

    public function sortIndicator(string $column): string
    {
        return $this->sortColumn !== $column ? '-' : ($this->sortDirection === 'asc' ? '^' : 'v');
    }

    public function canExport(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User && $user->can('Export:Order') && $user->can('Export:Payment');
    }

    public function toggleAllRows(): void
    {
        $this->authorizeExport();

        $rowKeys = $this->periodRowKeys();
        $selectedKeys = $this->validSelectedRows();

        $this->selectedRows = $rowKeys !== [] && array_diff($rowKeys, $selectedKeys) === []
            ? []
            : $rowKeys;
    }

    public function areAllRowsSelected(): bool
    {
        $rowKeys = $this->periodRowKeys();

        return $rowKeys !== [] && array_diff($rowKeys, $this->validSelectedRows()) === [];
    }

    public function export(string $format): StreamedResponse
    {
        $this->authorizeExport();

        return $this->downloadRows($this->periodRows(), $format);
    }

    public function exportSelected(string $format): StreamedResponse
    {
        $this->authorizeExport();

        $rows = $this->selectedPeriodRows();
        abort_if($rows->isEmpty(), 422);

        return $this->downloadRows($rows, $format);
    }

    private function downloadRows(Collection $rows, string $format): StreamedResponse
    {
        $method = match (strtolower($format)) {
            'xlsx', 'excel' => 'downloadExcel',
            'csv' => 'downloadCsv',
            'pdf' => 'downloadPdf',
            default => abort(422),
        };
        $definition = new TableExportDefinition(
            title: 'Sales Revenue Trend Detail',
            columns: [
                'period' => 'Period',
                'orders' => 'Orders',
                'subtotal' => 'Subtotal',
                'discount' => 'Discount',
                'shipping_cost' => 'Shipping Cost',
                'total' => 'Total',
            ],
            monetary: ['subtotal', 'discount', 'shipping_cost', 'total'],
        );

        return app(TableExportService::class)->{$method}(
            $rows,
            Order::class,
            $definition,
        );
    }

    private function authorizeExport(): void
    {
        abort_unless($this->canExport(), 403);
    }

    /** @return list<string> */
    private function periodRowKeys(): array
    {
        return $this->periodRows()->pluck('report_row_key')->all();
    }

    /** @return list<string> */
    private function validSelectedRows(): array
    {
        return collect($this->selectedRows)
            ->filter(fn (mixed $key): bool => is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function selectedPeriodRows(): Collection
    {
        $selectedKeys = array_fill_keys($this->validSelectedRows(), true);

        return $this->periodRows()
            ->filter(fn (array $row): bool => isset($selectedKeys[$row['report_row_key']]))
            ->values();
    }
}
