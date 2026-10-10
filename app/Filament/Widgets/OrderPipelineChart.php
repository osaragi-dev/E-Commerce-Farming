<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Support\DashboardDateRange;
use App\Models\Order;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrderPipelineChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Order pipeline';

    protected ?string $description = 'Orders created in the selected date range, grouped by current fulfillment stage.';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:Order') ?? false;
    }

    protected function getData(): array
    {
        $dateRange = DashboardDateRange::fromFilters($this->pageFilters);
        $counts = Order::query()
            ->where('created_at', '>=', $dateRange->start)
            ->where('created_at', '<', $dateRange->endExclusive())
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statuses = [
            OrderStatus::WaitingPayment,
            OrderStatus::ReadyToProcess,
            OrderStatus::Packing,
            OrderStatus::Shipping,
            OrderStatus::Done,
            OrderStatus::Cancelled,
        ];

        $values = [];

        foreach ($statuses as $status) {
            $value = (int) ($counts[$status->value] ?? 0);

            if ($status === OrderStatus::ReadyToProcess) {
                $value += (int) ($counts[OrderStatus::WaitingConfirmation->value] ?? 0);
            }

            $values[] = $value;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Orders',
                    'data' => $values,
                    'backgroundColor' => [
                        '#f59e0b',
                        '#64748b',
                        '#0ea5e9',
                        '#1f4d3d',
                        '#16a34a',
                        '#ef4444',
                    ],
                    'borderRadius' => 5,
                ],
            ],
            'labels' => array_map(
                fn (OrderStatus $status): string => $status->getLabel(),
                $statuses,
            ),
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
            ({
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                    },
                    y: {
                        grid: { display: false },
                    },
                },
            })
            JS);
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
