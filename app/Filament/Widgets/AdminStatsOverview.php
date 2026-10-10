<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\DashboardDateRange;
use App\Filament\Support\Rupiah;
use App\Models\Order;
use App\Models\Payment;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = -2;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('ViewAny:Customer')
            || $user->can('ViewAny:Order')
            || $user->can('ViewAny:Payment')
        );
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $dateRange = DashboardDateRange::fromFilters($this->pageFilters);
        $dateDescription = $dateRange->start->isSameDay($dateRange->end)
            ? $dateRange->start->format('M d, Y')
            : 'from '.$dateRange->start->format('M d, Y').' to '.$dateRange->end->format('M d, Y');

        $stats = [];

        if ($user->can('ViewAny:Payment')) {
            $paidSales = Payment::query()
                ->where('status', PaymentStatus::Paid->value)
                ->whereHas('order')
                ->where('verified_at', '>=', $dateRange->start)
                ->where('verified_at', '<', $dateRange->endExclusive())
                ->sum('amount');

            $pendingPayments = Payment::query()
                ->whereHas('order')
                ->whereIn('status', [
                    PaymentStatus::Unpaid->value,
                    PaymentStatus::WaitingConfirm->value,
                ])
                ->where('created_at', '>=', $dateRange->start)
                ->where('created_at', '<', $dateRange->endExclusive())
                ->count();
            $outstandingPayments = Payment::query()
                ->where('status', PaymentStatus::Unpaid->value)
                ->whereHas('order')
                ->where('created_at', '>=', $dateRange->start)
                ->where('created_at', '<', $dateRange->endExclusive())
                ->sum('amount');

            $stats[] = Stat::make(
                'Paid Sales',
                Rupiah::formatWithPrefix($paidSales) ?? 'Rp 0,00',
            )
                ->description('Confirmed payments '.$dateDescription)
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success')
                ->icon('heroicon-o-banknotes');

            $stats[] = Stat::make('Payments to Review', $pendingPayments)
                ->description('Unpaid or waiting confirmation')
                ->descriptionIcon('heroicon-o-clock')
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle')
                ->url(PaymentResource::getUrl());

            $stats[] = Stat::make(
                'Outstanding Payments',
                Rupiah::formatWithPrefix($outstandingPayments) ?? 'Rp 0,00',
            )
                ->description('Total unpaid payment amount')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('warning')
                ->icon('heroicon-o-clock')
                ->url(PaymentResource::getUrl());
        }

        if ($user->can('ViewAny:Order')) {
            $activeStatuses = array_map(
                fn (OrderStatus $status): string => $status->value,
                OrderStatus::activeStates(),
            );

            $stats[] = Stat::make(
                'New Orders',
                Order::query()
                    ->where('created_at', '>=', $dateRange->start)
                    ->where('created_at', '<', $dateRange->endExclusive())
                    ->count(),
            )
                ->description('Orders created '.$dateDescription)
                ->descriptionIcon('heroicon-o-shopping-bag')
                ->color('primary')
                ->icon('heroicon-o-shopping-bag')
                ->url(OrderResource::getUrl());

            $stats[] = Stat::make(
                'Active Orders',
                Order::query()
                    ->whereIn('status', $activeStatuses)
                    ->where('created_at', '>=', $dateRange->start)
                    ->where('created_at', '<', $dateRange->endExclusive())
                    ->count(),
            )
                ->description('Awaiting fulfillment')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->color('info')
                ->icon('heroicon-o-truck')
                ->url(OrderResource::getUrl());
        }

        return $stats;
    }
}
