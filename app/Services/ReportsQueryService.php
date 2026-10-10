<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class ReportsQueryService
{
    /** @return array{total_sales: float, total_orders: int, done_orders: int, cancelled_orders: int, paid_orders: int, unpaid_or_waiting_orders: int, rejected_orders: int, void_orders: int} */
    public function summaryForPeriod(string $startDate, string $endDate, bool $includeOrderMetrics = true, bool $includePaymentMetrics = true): array
    {
        [$start, $end] = $this->dateBoundaries($startDate, $endDate);
        $summary = [
            'total_sales' => 0.0,
            'total_orders' => 0,
            'done_orders' => 0,
            'cancelled_orders' => 0,
            'paid_orders' => 0,
            'unpaid_or_waiting_orders' => 0,
            'rejected_orders' => 0,
            'void_orders' => 0,
        ];

        if ($includeOrderMetrics) {
            $orderQuery = Order::query()
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end);

            $summary['total_orders'] = (clone $orderQuery)->count();
            $summary['done_orders'] = (clone $orderQuery)
                ->whereIn('status', [OrderStatus::Done->value, OrderStatus::PickedUp->value])
                ->count();
            $summary['cancelled_orders'] = (clone $orderQuery)
                ->where('status', OrderStatus::Cancelled->value)
                ->count();
        }

        if ($includePaymentMetrics) {
            $confirmedPayments = $this->confirmedPaymentsBetween($start, $end);
            $summary['total_sales'] = (float) (clone $confirmedPayments)->sum('payments.amount');
            $summary['paid_orders'] = (clone $confirmedPayments)->count('payments.id');
            $summary['unpaid_or_waiting_orders'] = $this->paymentCountForStatuses(
                [PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm],
                $start,
                $end,
            );
            $summary['rejected_orders'] = $this->paymentCountForStatuses([PaymentStatus::Rejected], $start, $end);
            $summary['void_orders'] = $this->paymentCountForStatuses([PaymentStatus::Void], $start, $end);
        }

        return $summary;
    }

    /** @return Builder<OrderItem> */
    public function productSalesQuery(string $startDate, string $endDate, string $sortColumn = 'revenue', string $sortDirection = 'desc'): Builder
    {
        [$start, $end] = $this->dateBoundaries($startDate, $endDate);
        $confirmedPaymentAttempts = $this->confirmedPaymentsBetween($start, $end)
            ->select(['payments.id', 'payments.order_id']);

        $sortColumns = [
            'menu' => 'menu',
            'product_category' => 'product_category',
            'quantity_sold' => 'quantity_sold',
            'revenue' => 'revenue',
        ];
        $sortColumn = $sortColumns[$sortColumn] ?? 'revenue';
        $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->joinSub($confirmedPaymentAttempts, 'confirmed_payments', fn (JoinClause $join): JoinClause => $join
                ->on('confirmed_payments.order_id', '=', 'order_items.order_id'))
            ->leftJoin('products', fn (JoinClause $join): JoinClause => $join
                ->on('products.id', '=', 'order_items.product_id')
                ->whereNull('products.deleted_at'))
            ->leftJoin('categories', fn (JoinClause $join): JoinClause => $join
                ->on('categories.id', '=', 'products.category_id')
                ->whereNull('categories.deleted_at'))
            ->whereNull('orders.deleted_at')
            ->selectRaw('order_items.product_id as report_product_id')
            ->selectRaw('COALESCE(products.name, order_items.product_name) as menu')
            ->selectRaw("COALESCE(categories.name, '-') as product_category")
            ->selectRaw('SUM(order_items.quantity) as quantity_sold')
            ->selectRaw('SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_id')
            ->groupByRaw('COALESCE(products.name, order_items.product_name)')
            ->groupByRaw("COALESCE(categories.name, '-')")
            ->orderBy($sortColumn, $sortDirection)
            ->when($sortColumn !== 'menu', fn (Builder $query): Builder => $query->orderBy('menu'));
    }

    /** @return Collection<int, array{payment_method: string, transaction_count: int, amount: float, percentage: float}> */
    public function paymentBreakdownForPeriod(string $startDate, string $endDate): Collection
    {
        [$start, $end] = $this->dateBoundaries($startDate, $endDate);
        $transactions = $this->confirmedPaymentsBetween($start, $end)
            ->select('payments.method')
            ->selectRaw('COUNT(payments.id) as transaction_count')
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('payments.method')
            ->get();
        $totalAmount = (float) $transactions->sum(fn (Payment $payment): float => (float) $payment->amount);

        return $transactions->map(function (Payment $payment) use ($totalAmount): array {
            $method = $payment->method;

            return [
                'payment_method' => $method instanceof PaymentMethod ? $method->getLabel() : (string) $method,
                'transaction_count' => (int) $payment->transaction_count,
                'amount' => (float) $payment->amount,
                'percentage' => $totalAmount > 0 ? round(((float) $payment->amount / $totalAmount) * 100, 2) : 0.0,
            ];
        })->values();
    }

    /**
     * @return list<array{label: string, amount: float}>
     */
    public function revenueTrend(string $startDate, string $endDate, string $grouping): array
    {
        [$start, $end] = $this->dateBoundaries($startDate, $endDate);
        $startDay = CarbonImmutable::parse($startDate, 'Asia/Jakarta')->startOfDay();
        $endDay = CarbonImmutable::parse($endDate, 'Asia/Jakarta')->startOfDay();
        $grouping = in_array($grouping, ['daily', 'weekly', 'monthly', 'yearly'], true) ? $grouping : 'daily';
        $buckets = [];

        for ($date = $startDay; $date->lessThanOrEqualTo($endDay); $date = $date->addDay()) {
            [$key, $label] = $this->trendBucket($date, $grouping);
            $buckets[$key] ??= ['label' => $label, 'amount' => 0.0];
        }

        $this->confirmedPaymentsBetween($start, $end)
            ->select(['payments.id', 'payments.verified_at', 'payments.amount'])
            ->lazyById(500, 'payments.id')
            ->each(function (Payment $payment) use (&$buckets, $grouping): void {
                if ($payment->verified_at === null) {
                    return;
                }

                [$key] = $this->trendBucket(
                    CarbonImmutable::instance($payment->verified_at)->timezone('Asia/Jakarta'),
                    $grouping,
                );

                if (array_key_exists($key, $buckets)) {
                    $buckets[$key]['amount'] += (float) $payment->amount;
                }
            });

        return array_values($buckets);
    }

    /**
     * @return list<array{label: string, period: string, amount: float, orders: int, subtotal: float, discount: float, shipping_cost: float, total: float}>
     */
    public function revenueTrendSummary(string $startDate, string $endDate, string $grouping): array
    {
        [$start, $end] = $this->dateBoundaries($startDate, $endDate);
        $startDay = CarbonImmutable::parse($startDate, 'Asia/Jakarta')->startOfDay();
        $endDay = CarbonImmutable::parse($endDate, 'Asia/Jakarta')->startOfDay();
        $grouping = in_array($grouping, ['daily', 'weekly', 'monthly', 'yearly'], true) ? $grouping : 'daily';
        $buckets = [];

        for ($date = $startDay; $date->lessThanOrEqualTo($endDay); $date = $date->addDay()) {
            [$key, $label] = $this->trendBucket($date, $grouping);
            $buckets[$key] ??= [
                'label' => $label,
                'period' => $label,
                'amount' => 0.0,
                'orders' => 0,
                'subtotal' => 0.0,
                'discount' => 0.0,
                'shipping_cost' => 0.0,
                'total' => 0.0,
            ];
        }

        $this->confirmedPaymentsBetween($start, $end)
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereNull('orders.deleted_at')
            ->select('payments.*')
            ->selectRaw('orders.subtotal as order_subtotal')
            ->selectRaw('orders.discount_amount as order_discount')
            ->selectRaw('orders.shipping_cost as order_shipping_cost')
            ->selectRaw('orders.total as order_total')
            ->lazyById(500, 'payments.id', 'id')
            ->each(function (Payment $payment) use (&$buckets, $grouping): void {
                if ($payment->verified_at === null) {
                    return;
                }

                [$key] = $this->trendBucket(
                    CarbonImmutable::instance($payment->verified_at)->timezone('Asia/Jakarta'),
                    $grouping,
                );

                if (array_key_exists($key, $buckets)) {
                    $buckets[$key]['amount'] += (float) $payment->amount;
                    $buckets[$key]['orders']++;
                    $buckets[$key]['subtotal'] += (float) $payment->order_subtotal;
                    $buckets[$key]['discount'] += (float) $payment->order_discount;
                    $buckets[$key]['shipping_cost'] += (float) $payment->order_shipping_cost;
                    $buckets[$key]['total'] += (float) $payment->order_total;
                }
            });

        return array_values($buckets);
    }

    /** @param list<PaymentStatus> $statuses */
    private function paymentCountForStatuses(array $statuses, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Payment::query()
            ->currentAttempt()
            ->whereIn('payments.status', array_map(fn (PaymentStatus $status): string => $status->value, $statuses))
            ->where('payments.created_at', '>=', $start)
            ->where('payments.created_at', '<', $end)
            ->whereHas('order')
            ->count('payments.id');
    }

    /** @return Builder<Payment> */
    private function confirmedPaymentsBetween(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Payment::query()
            ->currentAttempt()
            ->where('payments.status', PaymentStatus::Paid->value)
            ->where('payments.verified_at', '>=', $start)
            ->where('payments.verified_at', '<', $end)
            ->whereHas('order');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dateBoundaries(string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate, 'Asia/Jakarta')
            ->startOfDay()
            ->setTimezone(config('app.timezone'));
        $end = CarbonImmutable::parse($endDate, 'Asia/Jakarta')
            ->addDay()
            ->startOfDay()
            ->setTimezone(config('app.timezone'));

        return [$start, $end];
    }

    /** @return array{0: string, 1: string} */
    private function trendBucket(CarbonImmutable $date, string $grouping): array
    {
        return match ($grouping) {
            'weekly' => [
                $date->startOfWeek()->format('o-W'),
                'Week of '.$date->startOfWeek()->format('d M Y'),
            ],
            'monthly' => [$date->format('Y-m'), $date->format('M Y')],
            'yearly' => [$date->format('Y'), $date->format('Y')],
            default => [$date->toDateString(), $date->format('d M Y')],
        };
    }
}
