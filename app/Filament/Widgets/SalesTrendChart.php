<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Filament\Support\DashboardDateRange;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SalesTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Paid sales';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:Payment') ?? false;
    }

    public function getDescription(): ?string
    {
        $filters = $this->pageFilters;
        $dateRange = DashboardDateRange::fromFilters($filters);

        $activeFilter = match ($filters['time_range'] ?? 'today') {
            'yesterday' => 'Yesterday',
            'last_7_days' => 'Last 7 days',
            'last_30_days' => 'Last 30 days',
            'this_week' => 'This week',
            'this_month' => 'This month',
            'custom' => $this->customRangeLabel($dateRange),
            default => 'Today',
        };

        return 'Active filter: '.$activeFilter;
    }

    protected function getData(): array
    {
        $dateRange = DashboardDateRange::fromFilters($this->pageFilters);
        $isHourly = $dateRange->start->isSameDay($dateRange->end);
        $bucketIndexes = [];
        $labels = [];
        $values = [];
        $bucketDate = $dateRange->start->startOfDay();
        $lastBucketDate = $dateRange->end->startOfDay();

        if ($isHourly) {
            $now = CarbonImmutable::now($dateRange->end->getTimezone());
            $lastBucketDate = $dateRange->end->isSameDay($now)
                ? $now->startOfHour()
                : $lastBucketDate->addHours(23);
        }

        while ($bucketDate->lessThanOrEqualTo($lastBucketDate)) {
            $bucketKey = $isHourly ? $bucketDate->format('Y-m-d H') : $bucketDate->toDateString();
            $bucketIndexes[$bucketKey] = count($labels);
            $labels[] = $isHourly ? $bucketDate->format('H:00') : $bucketDate->format('M d, Y');
            $values[] = 0.0;
            $bucketDate = $isHourly ? $bucketDate->addHour() : $bucketDate->addDay();
        }

        Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->whereHas('order')
            ->where('verified_at', '>=', $dateRange->start)
            ->where('verified_at', '<', $dateRange->endExclusive())
            ->get(['verified_at', 'amount'])
            ->each(function (Payment $payment) use (&$values, $bucketIndexes, $isHourly): void {
                if ($payment->verified_at === null) {
                    return;
                }

                $bucketDate = $isHourly
                    ? $payment->verified_at->copy()->startOfHour()
                    : $payment->verified_at->copy()->startOfDay();
                $bucketKey = $isHourly ? $bucketDate->format('Y-m-d H') : $bucketDate->toDateString();
                $bucketIndex = $bucketIndexes[$bucketKey] ?? null;

                if ($bucketIndex !== null) {
                    $values[$bucketIndex] += (float) $payment->amount;
                }
            });

        return [
            'datasets' => [
                [
                    'label' => 'Paid sales',
                    'data' => $values,
                    'borderColor' => '#1f4d3d',
                    'backgroundColor' => 'rgb(31 77 61 / 14%)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => $labels,
        ];
    }

    private function customRangeLabel(DashboardDateRange $dateRange): string
    {
        $startDate = $dateRange->start->format('M d, Y');

        if ($dateRange->start->isSameDay($dateRange->end)) {
            return 'Custom range ('.$startDate.')';
        }

        return 'Custom range ('.$startDate.' - '.$dateRange->end->format('M d, Y').')';
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
            ({
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => new Intl.NumberFormat('id-ID', {
                                style: 'currency',
                                currency: 'IDR',
                                maximumFractionDigits: 0,
                            }).format(context.parsed.y ?? 0),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => new Intl.NumberFormat('id-ID', {
                                notation: 'compact',
                                maximumFractionDigits: 1,
                            }).format(value).replace(/\s*rb$/, ' K'),
                        },
                    },
                },
            })
            JS);
    }

    protected function getType(): string
    {
        return 'line';
    }
}
