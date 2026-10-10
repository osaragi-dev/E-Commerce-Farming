<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Filament\Pages\Reports;
use App\Filament\Pages\SalesReports;
use App\Filament\Pages\SalesRevenueTrendDetail;
use App\Filament\Support\Rupiah;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportsQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function giveReportsPermission(User $user, string $permission): void
{
    $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
}

function reportsDateTime(string $dateTime): Carbon
{
    return Carbon::parse($dateTime, 'Asia/Jakarta')->setTimezone(config('app.timezone'));
}

/** @param array<string, mixed> $attributes */
function createReportPayment(Order $order, array $attributes): Payment
{
    return Payment::factory()->for($order)->create($attributes);
}

test('reports use standard rupiah formatting for whole and fractional values', function (): void {
    expect(Rupiah::formatWithPrefix('10000.00'))->toBe('Rp 10.000,00')
        ->and(Rupiah::formatWithPrefix('10000.50'))->toBe('Rp 10.000,50');
});

test('the shared report filter applies one date range and removes the split report headings', function (): void {
    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    Livewire::test(Reports::class)
        ->set('startDate', '2026-10-01')
        ->set('endDate', '2026-10-03')
        ->call('previewSalesReports')
        ->assertDispatched('sales-report-filter-applied')
        ->assertSee('Reports Filters', false)
        ->assertSee('Start Date')
        ->assertSee('End Date')
        ->assertSee('Rejected Payment')
        ->assertSee('Void Payment')
        ->assertDontSee('Rejected Orders')
        ->assertDontSee('Void Orders')
        ->assertSee('No confirmed payments or orders in the selected range.')
        ->assertDontSee('Daily Reports')
        ->assertDontSee('Revenue:')
        ->assertDontSee('Sales Reports Filters')
        ->assertDontSee('Top Selling Items')
        ->assertSet('salesPeriod', ['start' => '2026-10-01', 'end' => '2026-10-03']);
});

test('reports default to today with daily grouping', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'Asia/Jakarta'));

    try {
        $user = User::factory()->sso()->create();
        giveReportsPermission($user, 'ViewAny:Order');
        giveReportsPermission($user, 'ViewAny:Payment');
        $this->actingAs($user, 'web');

        Livewire::test(Reports::class)
            ->assertSet('startDate', '2026-10-07')
            ->assertSet('endDate', '2026-10-07')
            ->assertSet('salesPeriod', ['start' => '2026-10-07', 'end' => '2026-10-07'])
            ->assertSet('revenueGrouping', 'daily')
            ->assertSee('Applied range:')
            ->assertSeeInOrder(['Sales Summary', 'Amount Trend', 'Payment Breakdown', 'Product Sales'])
            ->assertSee('No confirmed payments or orders in the selected range.')
            ->assertSee('07 Oct 2026')
            ->assertSee('Qty Sold')
            ->assertSee('No product sales in this date range')
            ->assertSee('min-w-[60rem] table-fixed', false)
            ->assertSee('min-w-[48rem] table-fixed', false)
            ->assertDontSee('cursor-crosshair');
    } finally {
        Carbon::setTestNow();
    }
});

test('reports render rupiah amounts with standard decimals', function (): void {
    $date = reportsDateTime('2026-10-05 12:00:00');
    $order = Order::factory()->create([
        'created_at' => $date,
        'subtotal' => '10000.00',
        'discount_amount' => '0.00',
        'shipping_cost' => '0.00',
        'total' => '10000.00',
    ]);
    createReportPayment($order, [
        'method' => PaymentMethod::Cash,
        'amount' => '10000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => $date,
        'verified_at' => $date,
    ]);

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports')
        ->assertSee('Rp 10.000,00')
        ->assertSee('Amount: Rp 10.000,00');
});

test('amount trend renders payment bars and an orders line across periods', function (): void {
    foreach ([['2026-10-05 12:00:00', '10000.50'], ['2026-10-06 12:00:00', '15000.00']] as [$dateTime, $amount]) {
        $date = reportsDateTime($dateTime);
        $order = Order::factory()->create(['created_at' => $date]);
        createReportPayment($order, [
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'created_at' => $date,
            'verified_at' => $date,
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports')
        ->assertSee('Amount and Orders by period', false)
        ->assertDontSee('Amount and order volume')
        ->assertDontSee('Hover over a data point for period details.')
        ->assertSee('role="tooltip"', false)
        ->assertSee('Amount</span>', false)
        ->assertSee('Orders</span>', false)
        ->assertSee('fill="url(#reports-revenue-gradient)"', false)
        ->assertSee('stroke="#d07a24"', false)
        ->assertSee('Amount: Rp 10.000,50')
        ->assertSee('Orders: 1')
        ->assertSee('cursor-default')
        ->assertDontSee('cursor-crosshair');
});

test('amount trend has full width grouping controls and no separate custom range', function (): void {
    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports')
        ->assertSee('grid w-full grid-cols-2 gap-2 sm:grid-cols-4', false)
        ->assertDontSee('Custom Range')
        ->assertDontSee('Trend Start Date')
        ->assertDontSee('Trend End Date');
});

test('reports is available to users with either order or payment access', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('reports_viewer', 'web'));
    $this->actingAs($user, 'web');

    expect(Reports::canAccess())->toBeFalse();
    expect(Reports::getNavigationGroup())->toBe('Operations');
    $this->get(Reports::getUrl())->assertForbidden();

    $role = $user->roles()->firstOrFail();
    $role->givePermissionTo(Permission::findOrCreate('ViewAny:Order', 'web'));
    expect(Reports::canAccess())->toBeTrue();
    $this->get(Reports::getUrl())->assertOk()->assertSee('Reports Filters');

    $role->revokePermissionTo('ViewAny:Order');
    $role->givePermissionTo(Permission::findOrCreate('ViewAny:Payment', 'web'));
    expect(Reports::canAccess())->toBeTrue();
});

test('summary cards use Jakarta boundaries and only the current payment attempt', function (): void {
    $start = reportsDateTime('2026-10-05 00:00:00');
    $doneOrder = Order::factory()->create([
        'status' => OrderStatus::Done,
        'created_at' => $start,
    ]);
    createReportPayment($doneOrder, [
        'method' => PaymentMethod::Cash,
        'amount' => '100000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => reportsDateTime('2026-10-04 23:59:59'),
        'verified_at' => $start,
    ]);

    $rejectedOrder = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'created_at' => $start,
    ]);
    createReportPayment($rejectedOrder, [
        'attempt_number' => 1,
        'amount' => '900000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => $start,
        'verified_at' => $start,
    ]);
    createReportPayment($rejectedOrder, [
        'attempt_number' => 2,
        'amount' => '900000.00',
        'status' => PaymentStatus::Rejected,
        'created_at' => $start,
        'verified_at' => null,
    ]);

    foreach ([PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm] as $status) {
        $order = Order::factory()->create(['created_at' => $start]);
        createReportPayment($order, [
            'status' => $status,
            'created_at' => $start,
            'verified_at' => null,
        ]);
    }

    $voidOrder = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'created_at' => $start,
    ]);
    createReportPayment($voidOrder, [
        'status' => PaymentStatus::Void,
        'created_at' => $start,
        'verified_at' => null,
    ]);

    $outsideOrder = Order::factory()->create([
        'status' => OrderStatus::Done,
        'created_at' => reportsDateTime('2026-10-06 00:00:00'),
    ]);
    createReportPayment($outsideOrder, [
        'amount' => '700000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => reportsDateTime('2026-10-06 00:00:00'),
        'verified_at' => reportsDateTime('2026-10-06 00:00:00'),
    ]);

    $summary = app(ReportsQueryService::class)->summaryForPeriod('2026-10-05', '2026-10-05');

    expect($summary['total_sales'])->toBe(100000.0)
        ->and($summary['total_orders'])->toBe(5)
        ->and($summary['done_orders'])->toBe(1)
        ->and($summary['cancelled_orders'])->toBe(2)
        ->and($summary['paid_orders'])->toBe(1)
        ->and($summary['unpaid_or_waiting_orders'])->toBe(2)
        ->and($summary['rejected_orders'])->toBe(1)
        ->and($summary['void_orders'])->toBe(1);
});

test('every report section filters by its metric date across Jakarta range boundaries', function (): void {
    $category = Category::factory()->create(['name' => 'Seeds']);
    $product = Product::factory()->for($category)->create(['name' => 'Corn Seed']);

    $startBoundary = reportsDateTime('2026-10-05 00:00:00');
    $endBoundary = reportsDateTime('2026-10-06 23:59:59');
    $beforeRange = reportsDateTime('2026-10-04 23:59:59');
    $afterRange = reportsDateTime('2026-10-07 00:00:00');

    $startOrder = Order::factory()->create([
        'status' => OrderStatus::Done,
        'created_at' => $startBoundary,
        'subtotal' => '100000.00',
        'discount_amount' => '0.00',
        'shipping_cost' => '0.00',
        'total' => '100000.00',
    ]);
    createReportPayment($startOrder, [
        'method' => PaymentMethod::Cash,
        'amount' => '100000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => $beforeRange,
        'verified_at' => $startBoundary,
    ]);
    OrderItem::factory()->for($startOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Corn Seed',
        'quantity' => 1,
        'subtotal' => '100000.00',
    ]);

    $olderOrder = Order::factory()->create([
        'created_at' => $beforeRange,
        'subtotal' => '200000.00',
        'discount_amount' => '0.00',
        'shipping_cost' => '0.00',
        'total' => '200000.00',
    ]);
    createReportPayment($olderOrder, [
        'method' => PaymentMethod::Qris,
        'amount' => '200000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => reportsDateTime('2026-10-05 12:00:00'),
        'verified_at' => $endBoundary,
    ]);
    OrderItem::factory()->for($olderOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Corn Seed',
        'quantity' => 2,
        'subtotal' => '200000.00',
    ]);

    $endOrder = Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'created_at' => $endBoundary,
        'subtotal' => '300000.00',
        'discount_amount' => '0.00',
        'shipping_cost' => '0.00',
        'total' => '300000.00',
    ]);
    createReportPayment($endOrder, [
        'method' => PaymentMethod::Cash,
        'amount' => '300000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => $afterRange,
        'verified_at' => $endBoundary,
    ]);
    OrderItem::factory()->for($endOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Corn Seed',
        'quantity' => 3,
        'subtotal' => '300000.00',
    ]);

    $unpaidOrder = Order::factory()->create(['created_at' => $beforeRange]);
    createReportPayment($unpaidOrder, [
        'status' => PaymentStatus::Unpaid,
        'created_at' => $startBoundary,
        'verified_at' => null,
    ]);

    $waitingOrder = Order::factory()->create(['created_at' => $beforeRange]);
    createReportPayment($waitingOrder, [
        'status' => PaymentStatus::WaitingConfirm,
        'created_at' => $endBoundary,
        'verified_at' => null,
    ]);

    $rejectedOrder = Order::factory()->create(['created_at' => $beforeRange]);
    createReportPayment($rejectedOrder, [
        'status' => PaymentStatus::Rejected,
        'created_at' => $startBoundary,
        'verified_at' => null,
    ]);

    $voidOrder = Order::factory()->create(['created_at' => $beforeRange]);
    createReportPayment($voidOrder, [
        'status' => PaymentStatus::Void,
        'created_at' => $endBoundary,
        'verified_at' => null,
    ]);

    $outsideStatusOrder = Order::factory()->create(['created_at' => $beforeRange]);
    createReportPayment($outsideStatusOrder, [
        'status' => PaymentStatus::Rejected,
        'created_at' => $afterRange,
        'verified_at' => null,
    ]);

    $outsidePaidOrder = Order::factory()->create(['created_at' => $beforeRange]);
    createReportPayment($outsidePaidOrder, [
        'method' => PaymentMethod::Qris,
        'amount' => '900000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => $startBoundary,
        'verified_at' => $beforeRange,
    ]);
    OrderItem::factory()->for($outsidePaidOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Corn Seed',
        'quantity' => 9,
        'subtotal' => '900000.00',
    ]);

    $nextRangeOrder = Order::factory()->create([
        'status' => OrderStatus::Done,
        'created_at' => $afterRange,
        'subtotal' => '900000.00',
        'discount_amount' => '0.00',
        'shipping_cost' => '0.00',
        'total' => '900000.00',
    ]);
    createReportPayment($nextRangeOrder, [
        'method' => PaymentMethod::Qris,
        'amount' => '900000.00',
        'status' => PaymentStatus::Paid,
        'created_at' => $afterRange,
        'verified_at' => $afterRange,
    ]);
    OrderItem::factory()->for($nextRangeOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Corn Seed',
        'quantity' => 9,
        'subtotal' => '900000.00',
    ]);

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    $component = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports');

    $summaryCards = collect($component->instance()->summaryCards())->keyBy('label');
    $paymentsByMethod = $component->instance()->paymentBreakdownRows()->keyBy('payment_method');
    $revenueRows = collect($component->instance()->revenueTrendRows())->keyBy('period');
    $productSales = $component->instance()->productSalesRows()->sole();

    expect($summaryCards['Total Orders']['value'])->toBe('2')
        ->and($summaryCards['Done Orders']['value'])->toBe('1')
        ->and($summaryCards['Cancelled Orders']['value'])->toBe('1')
        ->and($summaryCards['Total Sales']['value'])->toBe('Rp 600.000,00')
        ->and($summaryCards['Paid Orders']['value'])->toBe('3')
        ->and($summaryCards['Unpaid / Waiting Confirmation Orders']['value'])->toBe('2')
        ->and($summaryCards['Rejected Payment']['value'])->toBe('1')
        ->and($summaryCards['Void Payment']['value'])->toBe('1')
        ->and($paymentsByMethod['Cash']['transaction_count'])->toBe(2)
        ->and($paymentsByMethod['Cash']['amount'])->toBe(400000.0)
        ->and($paymentsByMethod['QRIS']['transaction_count'])->toBe(1)
        ->and($paymentsByMethod['QRIS']['amount'])->toBe(200000.0)
        ->and($revenueRows['05 Oct 2026']['amount'])->toBe(100000.0)
        ->and($revenueRows['06 Oct 2026']['amount'])->toBe(500000.0)
        ->and((float) $productSales->quantity_sold)->toBe(6.0)
        ->and((float) $productSales->revenue)->toBe(600000.0);

    $component
        ->set('startDate', '2026-10-07')
        ->set('endDate', '2026-10-07')
        ->call('previewSalesReports');

    $newRangeCards = collect($component->instance()->summaryCards())->keyBy('label');

    expect($newRangeCards['Total Orders']['value'])->toBe('1')
        ->and($newRangeCards['Total Sales']['value'])->toBe('Rp 900.000,00')
        ->and($component->instance()->revenueTrendRows())->toHaveCount(1)
        ->and($component->instance()->revenueTrendRows()[0]['amount'])->toBe(900000.0)
        ->and($component->instance()->paymentBreakdownRows()->sole()['amount'])->toBe(900000.0)
        ->and((float) $component->instance()->productSalesRows()->sole()->quantity_sold)->toBe(9.0)
        ->and((float) $component->instance()->productSalesRows()->sole()->revenue)->toBe(900000.0);
});

test('product sales combine variants and use item subtotals for confirmed payments only', function (): void {
    $category = Category::factory()->create(['name' => 'Feed']);
    $product = Product::factory()->for($category)->create(['name' => 'Starter Feed']);
    $paidOrder = Order::factory()->create(['created_at' => reportsDateTime('2026-10-05 00:00:00')]);
    createReportPayment($paidOrder, [
        'amount' => '80000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-05 00:00:00'),
    ]);
    OrderItem::factory()->for($paidOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Starter Feed',
        'variant_name' => 'Small bag',
        'quantity' => 2,
        'subtotal' => '20000.00',
    ]);
    OrderItem::factory()->for($paidOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Starter Feed',
        'variant_name' => 'Large bag',
        'quantity' => 3,
        'subtotal' => '30000.00',
    ]);

    $unpaidOrder = Order::factory()->create();
    createReportPayment($unpaidOrder, ['status' => PaymentStatus::Unpaid]);
    OrderItem::factory()->for($unpaidOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Starter Feed',
        'quantity' => 100,
        'subtotal' => '1000000.00',
    ]);

    $nextDayOrder = Order::factory()->create(['created_at' => reportsDateTime('2026-10-06 00:00:00')]);
    createReportPayment($nextDayOrder, [
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-06 00:00:00'),
    ]);
    OrderItem::factory()->for($nextDayOrder)->create([
        'product_id' => $product->id,
        'product_name' => 'Starter Feed',
        'quantity' => 50,
        'subtotal' => '500000.00',
    ]);

    $rows = app(ReportsQueryService::class)->productSalesQuery('2026-10-05', '2026-10-05')->get();
    $item = $rows->sole();

    expect($item->menu)->toBe('Starter Feed')
        ->and($item->product_category)->toBe('Feed')
        ->and((float) $item->quantity_sold)->toBe(5.0)
        ->and((float) $item->revenue)->toBe(50000.0);
});

test('payment breakdown calculates amount shares and supports sorting each column', function (): void {
    foreach ([[PaymentMethod::Cash, '150000.00'], [PaymentMethod::Cash, '150000.00'], [PaymentMethod::Qris, '100000.00']] as [$method, $amount]) {
        $order = Order::factory()->create();
        createReportPayment($order, [
            'method' => $method,
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime('2026-10-05 12:00:00'),
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    $component = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports');
    $rows = $component->instance()->paymentBreakdownRows()->keyBy('payment_method');

    expect($rows['Cash']['transaction_count'])->toBe(2)
        ->and($rows['Cash']['amount'])->toBe(300000.0)
        ->and($rows['Cash']['percentage'])->toBe(75.0)
        ->and($rows['QRIS']['percentage'])->toBe(25.0);

    $component->call('sortTable', 'paymentBreakdown', 'percentage')
        ->assertSeeInOrder(['QRIS', 'Cash'])
        ->call('sortTable', 'paymentBreakdown', 'percentage')
        ->assertSeeInOrder(['Cash', 'QRIS']);

    foreach ([
        'payment_method' => ['Cash', 'QRIS'],
        'transaction_count' => ['QRIS', 'Cash'],
        'amount' => ['QRIS', 'Cash'],
        'percentage' => ['QRIS', 'Cash'],
    ] as $column => $expectedOrder) {
        $component->call('sortTable', 'paymentBreakdown', $column);
        expect($component->instance()->paymentBreakdownSortColumn)->toBe($column)
            ->and($component->instance()->paymentBreakdownRows()->pluck('payment_method')->all())->toBe($expectedOrder);
    }
});

test('product sales sorts every column and exposes only its export actions', function (): void {
    foreach ([
        ['Z Seed', 'Z Category', 1, '100000.00'],
        ['A Feed', 'A Category', 2, '200000.00'],
    ] as [$name, $categoryName, $quantity, $amount]) {
        $category = Category::factory()->create(['name' => $categoryName]);
        $product = Product::factory()->for($category)->create(['name' => $name]);
        $order = Order::factory()->create();
        createReportPayment($order, [
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime('2026-10-05 12:00:00'),
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $name,
            'quantity' => $quantity,
            'subtotal' => $amount,
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    giveReportsPermission($user, 'Export:Order');
    $this->actingAs($user, 'web');

    $component = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports')
        ->assertSee('Product Categories')
        ->assertSee('Amount')
        ->assertDontSee('Top Selling Items')
        ->assertSee('Select all product sales')
        ->assertSee('Bulk Actions');

    foreach ([
        'menu' => ['A Feed', 'Z Seed'],
        'product_category' => ['A Feed', 'Z Seed'],
        'quantity_sold' => ['Z Seed', 'A Feed'],
        'revenue' => ['Z Seed', 'A Feed'],
    ] as $column => $expectedOrder) {
        $component->call('sortTable', 'productSales', $column);
        expect($component->instance()->productSalesRows()->pluck('menu')->all())->toBe($expectedOrder);
    }
});

test('selected product reports export only current rows in each standard format', function (
    string $selectionProperty,
    string $rowsMethod,
    string $selectAllMethod,
    string $exportMethod,
    string $format,
    string $serviceMethod,
    string $extension,
    string $exportLabel,
): void {
    foreach ([['A Product', 'A Category', '10000.00'], ['B Product', 'B Category', '20000.00']] as [$name, $categoryName, $amount]) {
        $category = Category::factory()->create(['name' => $categoryName]);
        $product = Product::factory()->for($category)->create(['name' => $name]);
        $order = Order::factory()->create();
        createReportPayment($order, [
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime('2026-10-05 12:00:00'),
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $name,
            'quantity' => 1,
            'subtotal' => $amount,
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    giveReportsPermission($user, 'Export:Order');
    $this->actingAs($user, 'web');

    $component = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports');
    $rows = $component->instance()->{$rowsMethod}();
    $rowKeys = $rows->pluck('report_row_key')->all();
    $selected = $rows->first();

    expect($rows)->toHaveCount(2)
        ->and($rowKeys)->toHaveCount(2);

    $component
        ->call($selectAllMethod)
        ->assertSet($selectionProperty, $rowKeys)
        ->call($selectAllMethod)
        ->assertSet($selectionProperty, []);

    $response = response()->streamDownload(static fn () => print 'selected', "orders-selected.{$extension}");
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive($serviceMethod)
        ->once()
        ->withArgs(fn (Collection $records, string $model, TableExportDefinition $definition): bool => $records->pluck('menu')->all() === [$selected->menu]
            && $model === Order::class
            && $definition->columns === [
                'menu' => 'Product',
                'product_category' => 'Product Categories',
                'quantity_sold' => 'Qty Sold',
                'revenue' => 'Amount',
            ])
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    $component
        ->set($selectionProperty, [$selected->report_row_key])
        ->assertSee($exportLabel)
        ->call($exportMethod, $format)
        ->assertFileDownloaded("orders-selected.{$extension}");
})->with([
    'product sales Excel' => ['productSalesSelectedRows', 'productSalesRows', 'toggleAllProductSalesRows', 'exportSelectedProductSales', 'excel', 'downloadExcel', 'xlsx', 'Export Selected Excel'],
    'product sales CSV' => ['productSalesSelectedRows', 'productSalesRows', 'toggleAllProductSalesRows', 'exportSelectedProductSales', 'csv', 'downloadCsv', 'csv', 'Export Selected CSV'],
    'product sales PDF' => ['productSalesSelectedRows', 'productSalesRows', 'toggleAllProductSalesRows', 'exportSelectedProductSales', 'pdf', 'downloadPdf', 'pdf', 'Export Selected PDF'],
]);

test('product sales page export follows applied date range and sort order', function (): void {
    foreach ([
        ['Z Product', '2026-10-05 12:00:00'],
        ['A Product', '2026-10-06 12:00:00'],
        ['Outside Range', '2026-10-04 12:00:00'],
    ] as [$name, $dateTime]) {
        $category = Category::factory()->create(['name' => 'Seeds']);
        $product = Product::factory()->for($category)->create(['name' => $name]);
        $order = Order::factory()->create();
        createReportPayment($order, [
            'amount' => '10000.00',
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime($dateTime),
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $name,
            'quantity' => 1,
            'subtotal' => '10000.00',
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    giveReportsPermission($user, 'Export:Order');
    $this->actingAs($user, 'web');

    $response = response()->streamDownload(static fn () => print 'filtered', 'orders-filtered.csv');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadCsv')
        ->once()
        ->withArgs(fn (Collection $records, string $model, TableExportDefinition $definition): bool => $records->pluck('menu')->all() === ['A Product', 'Z Product']
            && $model === Order::class
            && ($definition->columns['product_category'] ?? null) === 'Product Categories')
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports')
        ->call('sortTable', 'productSales', 'menu')
        ->call('exportProductSales', 'csv')
        ->assertFileDownloaded('orders-filtered.csv');
});

test('applying a new shared range clears product sales selections', function (): void {
    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    Livewire::test(Reports::class)
        ->set('productSalesSelectedRows', [str_repeat('b', 64)])
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports')
        ->assertSet('productSalesSelectedRows', []);
});

test('selected product exports reject row keys outside the active report result', function (): void {
    $category = Category::factory()->create(['name' => 'Seeds']);
    $product = Product::factory()->for($category)->create(['name' => 'Corn Seed']);
    $order = Order::factory()->create();
    createReportPayment($order, [
        'amount' => '10000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-05 12:00:00'),
    ]);
    OrderItem::factory()->for($order)->create([
        'product_id' => $product->id,
        'product_name' => 'Corn Seed',
        'quantity' => 1,
        'subtotal' => '10000.00',
    ]);

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    giveReportsPermission($user, 'Export:Order');
    $this->actingAs($user, 'web');

    $sales = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports');
    $salesKey = $sales->instance()->productSalesRows()->sole()->report_row_key;

    $sales
        ->set('startDate', '2026-10-06')
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports')
        ->set('productSalesSelectedRows', [$salesKey])
        ->call('exportSelectedProductSales', 'csv')
        ->assertUnprocessable();
});

test('revenue trend groups verified payments into daily weekly monthly and yearly buckets', function (): void {
    foreach ([
        ['2026-10-05 00:30:00', '100000.00'],
        ['2026-10-06 12:00:00', '200000.00'],
        ['2026-11-01 09:00:00', '400000.00'],
        ['2027-01-01 09:00:00', '800000.00'],
    ] as [$dateTime, $amount]) {
        $order = Order::factory()->create();
        createReportPayment($order, [
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime($dateTime),
        ]);
    }

    $reports = app(ReportsQueryService::class);

    expect($reports->revenueTrend('2026-10-05', '2026-10-06', 'daily'))
        ->toBe([
            ['label' => '05 Oct 2026', 'amount' => 100000.0],
            ['label' => '06 Oct 2026', 'amount' => 200000.0],
        ])
        ->and($reports->revenueTrend('2026-10-05', '2026-10-06', 'weekly'))
        ->toBe([['label' => 'Week of 05 Oct 2026', 'amount' => 300000.0]])
        ->and($reports->revenueTrend('2026-10-05', '2026-11-01', 'monthly'))
        ->toBe([
            ['label' => 'Oct 2026', 'amount' => 300000.0],
            ['label' => 'Nov 2026', 'amount' => 400000.0],
        ])
        ->and($reports->revenueTrend('2026-10-05', '2027-01-01', 'yearly'))
        ->toBe([
            ['label' => '2026', 'amount' => 700000.0],
            ['label' => '2027', 'amount' => 800000.0],
        ]);
});

test('revenue trend summaries aggregate current paid attempts and include both date boundaries', function (): void {
    $firstDayOrder = Order::factory()->create([
        'subtotal' => '100000.00',
        'discount_amount' => '10000.00',
        'shipping_cost' => '5000.00',
        'total' => '95000.00',
    ]);
    createReportPayment($firstDayOrder, [
        'attempt_number' => 1,
        'amount' => '900000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-05 00:00:00'),
    ]);
    createReportPayment($firstDayOrder, [
        'attempt_number' => 2,
        'amount' => '95000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-05 00:00:00'),
    ]);

    $lastDayOrder = Order::factory()->create([
        'subtotal' => '200000.00',
        'discount_amount' => '20000.00',
        'shipping_cost' => '10000.00',
        'total' => '190000.00',
    ]);
    createReportPayment($lastDayOrder, [
        'amount' => '190000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-06 23:59:59'),
    ]);

    $outsideOrder = Order::factory()->create(['total' => '500000.00']);
    createReportPayment($outsideOrder, [
        'amount' => '500000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-07 00:00:00'),
    ]);

    $reports = app(ReportsQueryService::class);
    $daily = $reports->revenueTrendSummary('2026-10-05', '2026-10-06', 'daily');
    $weekly = $reports->revenueTrendSummary('2026-10-05', '2026-10-06', 'weekly');

    expect($daily)->toBe([
        [
            'label' => '05 Oct 2026',
            'period' => '05 Oct 2026',
            'amount' => 95000.0,
            'orders' => 1,
            'subtotal' => 100000.0,
            'discount' => 10000.0,
            'shipping_cost' => 5000.0,
            'total' => 95000.0,
        ],
        [
            'label' => '06 Oct 2026',
            'period' => '06 Oct 2026',
            'amount' => 190000.0,
            'orders' => 1,
            'subtotal' => 200000.0,
            'discount' => 20000.0,
            'shipping_cost' => 10000.0,
            'total' => 190000.0,
        ],
    ])
        ->and($weekly)->toBe([
            [
                'label' => 'Week of 05 Oct 2026',
                'period' => 'Week of 05 Oct 2026',
                'amount' => 285000.0,
                'orders' => 2,
                'subtotal' => 300000.0,
                'discount' => 30000.0,
                'shipping_cost' => 15000.0,
                'total' => 285000.0,
            ],
        ]);
});

test('revenue summary sorts every column in both directions', function (): void {
    foreach ([
        ['2026-10-05 00:00:00', '100000.00', '10000.00', '5000.00', '95000.00'],
        ['2026-10-06 23:59:59', '200000.00', '20000.00', '10000.00', '190000.00'],
    ] as [$dateTime, $subtotal, $discount, $shippingCost, $total]) {
        $order = Order::factory()->create([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'shipping_cost' => $shippingCost,
            'total' => $total,
        ]);
        createReportPayment($order, [
            'amount' => $total,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime($dateTime),
        ]);
    }

    $secondLastDayOrder = Order::factory()->create([
        'subtotal' => '50000.00',
        'discount_amount' => '0.00',
        'shipping_cost' => '0.00',
        'total' => '50000.00',
    ]);
    createReportPayment($secondLastDayOrder, [
        'amount' => '50000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-06 23:59:59'),
    ]);

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    $component = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports');

    $ascending = [
        'period' => ['05 Oct 2026', '06 Oct 2026'],
        'orders' => ['05 Oct 2026', '06 Oct 2026'],
        'subtotal' => ['05 Oct 2026', '06 Oct 2026'],
        'discount' => ['05 Oct 2026', '06 Oct 2026'],
        'shipping_cost' => ['05 Oct 2026', '06 Oct 2026'],
        'total' => ['05 Oct 2026', '06 Oct 2026'],
    ];

    foreach ($ascending as $column => $expected) {
        $component->call('sortTable', 'revenueSummary', $column);

        if ($column === 'period') {
            expect($component->instance()->revenueSummarySortDirection)->toBe('desc');
            expect($component->instance()->revenueSummaryRows()->pluck('period')->all())
                ->toBe(array_reverse($expected));
            $component->call('sortTable', 'revenueSummary', $column);
        }

        expect($component->instance()->revenueSummaryRows()->pluck('period')->all())->toBe($expected);
        $component->call('sortTable', 'revenueSummary', $column);
        if ($column === 'period') {
            expect($component->instance()->revenueSummarySortDirection)->toBe('desc');
        }
        expect($component->instance()->revenueSummaryRows()->pluck('period')->all())
            ->toBe(array_reverse($expected));
    }
});

test('shared report range filters summary, payments, amount trend, and product sales', function (): void {
    $category = Category::factory()->create(['name' => 'Seeds']);
    $product = Product::factory()->for($category)->create(['name' => 'Corn Seed']);

    foreach ([['2026-10-05 12:00:00', '100000.00'], ['2026-10-06 12:00:00', '200000.00']] as [$dateTime, $amount]) {
        $date = reportsDateTime($dateTime);
        $order = Order::factory()->create(['created_at' => $date]);
        createReportPayment($order, [
            'method' => PaymentMethod::Cash,
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'created_at' => $date,
            'verified_at' => $date,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => 'Corn Seed',
            'quantity' => 1,
            'subtotal' => $amount,
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    $component = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports')
        ->set('revenueGrouping', 'daily')
        ->call('previewRevenueTrend');

    expect($component->instance()->salesPeriod)->toBe(['start' => '2026-10-05', 'end' => '2026-10-05'])
        ->and((float) $component->instance()->revenueTrendRows()[0]['amount'])->toBe(100000.0)
        ->and($component->instance()->revenueSummaryRows()->sole()['period'])->toBe('05 Oct 2026')
        ->and((float) $component->instance()->productSalesRows()->sole()->revenue)->toBe(100000.0)
        ->and($component->instance()->paymentBreakdownRows()->sole()['amount'])->toBe(100000.0)
        ->and(collect($component->instance()->summaryCards())->keyBy('label')['Total Orders']['value'])->toBe('1')
        ->and(collect($component->instance()->summaryCards())->keyBy('label')['Paid Orders']['value'])->toBe('1');

    $component
        ->set('startDate', '2026-10-06')
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports');

    expect($component->instance()->salesPeriod)->toBe(['start' => '2026-10-06', 'end' => '2026-10-06'])
        ->and((float) $component->instance()->revenueTrendRows()[0]['amount'])->toBe(200000.0)
        ->and($component->instance()->revenueSummaryRows()->sole()['period'])->toBe('06 Oct 2026')
        ->and((float) $component->instance()->productSalesRows()->sole()->revenue)->toBe(200000.0)
        ->and($component->instance()->paymentBreakdownRows()->sole()['amount'])->toBe(200000.0)
        ->and(collect($component->instance()->summaryCards())->keyBy('label')['Total Orders']['value'])->toBe('1');

    $detailUrl = $component->instance()->revenueTrendDetailUrl();
    expect($detailUrl)->toContain('startDate=2026-10-06')
        ->toContain('endDate=2026-10-06')
        ->toContain('grouping=daily');

    $component->assertSee($detailUrl);
});

test('revenue detail preserves search, hides export without both export permissions, and exports filtered rows', function (): void {
    foreach ([['2026-10-05 12:00:00', '100000.00'], ['2026-10-06 12:00:00', '200000.00']] as [$dateTime, $total]) {
        $order = Order::factory()->create([
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'shipping_cost' => '0.00',
            'total' => $total,
        ]);
        createReportPayment($order, [
            'amount' => $total,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime($dateTime),
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    giveReportsPermission($user, 'Export:Order');
    $user->assignRole(Role::findOrCreate('reports_detail_viewer', 'web'));
    $this->actingAs($user, 'web');

    $query = [
        'startDate' => '2026-10-05',
        'endDate' => '2026-10-06',
        'grouping' => 'daily',
    ];
    $url = SalesRevenueTrendDetail::getUrl($query);
    expect(SalesRevenueTrendDetail::canAccess())->toBeTrue();
    expect(SalesRevenueTrendDetail::shouldRegisterNavigation())->toBeFalse();
    $this->get($url)
        ->assertOk()
        ->assertSee('Sales Revenue Trend Detail')
        ->assertSee('Reports')
        ->assertSee('href="'.Reports::getUrl().'"', false)
        ->assertDontSee('Back to Reports')
        ->assertDontSee('Export CSV');

    $detail = Livewire::withQueryParams($query)->test(SalesRevenueTrendDetail::class)
        ->set('search', '06 Oct 2026')
        ->assertSee('06 Oct 2026')
        ->assertDontSee('Select all revenue trend periods')
        ->assertDontSee('Bulk Actions')
        ->assertDontSee('Export CSV');

    expect($detail->instance()->periodRows()->pluck('period')->all())->toBe(['06 Oct 2026']);
    $detail->call('export', 'csv')->assertForbidden();
    Livewire::withQueryParams($query)->test(SalesRevenueTrendDetail::class)
        ->set('search', '06 Oct 2026')
        ->call('exportSelected', 'csv')
        ->assertForbidden();

    $user->givePermissionTo(Permission::findOrCreate('Export:Payment', 'web'));
    Livewire::withQueryParams($query)->test(SalesRevenueTrendDetail::class)
        ->set('selectedRows', [str_repeat('a', 64)])
        ->call('exportSelected', 'csv')
        ->assertUnprocessable();

    $exportTime = Carbon::parse('2026-10-07 12:00:00', 'Asia/Jakarta');
    Carbon::setTestNow($exportTime);
    try {
        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $filename = 'orders-'.$exportTime->format('Ymd-His').'.'.$format;
            Livewire::withQueryParams($query)->test(SalesRevenueTrendDetail::class)
                ->set('search', '06 Oct 2026')
                ->call('export', $format)
                ->assertFileDownloaded($filename);
        }
    } finally {
        Carbon::setTestNow();
    }

    $orderOnlyUser = User::factory()->sso()->create();
    giveReportsPermission($orderOnlyUser, 'ViewAny:Order');
    $orderOnlyUser->assignRole(Role::findOrCreate('reports_order_viewer', 'web'));
    $this->actingAs($orderOnlyUser, 'web');
    $this->get($url)->assertForbidden();
});

test('revenue detail exports only selected rows that still match the search in each format', function (
    string $format,
    string $serviceMethod,
    string $extension,
): void {
    foreach ([['2026-10-05 12:00:00', '100000.00'], ['2026-10-06 12:00:00', '200000.00']] as [$dateTime, $total]) {
        $order = Order::factory()->create([
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'shipping_cost' => '0.00',
            'total' => $total,
        ]);
        createReportPayment($order, [
            'amount' => $total,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime($dateTime),
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    giveReportsPermission($user, 'Export:Order');
    giveReportsPermission($user, 'Export:Payment');
    $user->assignRole(Role::findOrCreate('reports_detail_exporter', 'web'));
    $this->actingAs($user, 'web');

    $query = [
        'startDate' => '2026-10-05',
        'endDate' => '2026-10-06',
        'grouping' => 'daily',
    ];
    $component = Livewire::withQueryParams($query)->test(SalesRevenueTrendDetail::class);
    $selectedKey = $component->instance()->periodRows()->firstWhere('period', '06 Oct 2026')['report_row_key'];

    $component
        ->set('search', '06 Oct 2026')
        ->call('toggleAllRows')
        ->assertSet('selectedRows', [$selectedKey])
        ->assertSee('Export Selected Excel')
        ->call('toggleAllRows')
        ->assertSet('selectedRows', [])
        ->set('selectedRows', [$selectedKey])
        ->assertSee('Export Selected Excel');

    $response = response()->streamDownload(static fn () => print 'selected', "orders-selected.{$extension}");
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive($serviceMethod)
        ->once()
        ->withArgs(fn (Collection $records, string $model, TableExportDefinition $definition): bool => $records->pluck('period')->all() === ['06 Oct 2026']
            && $model === Order::class
            && $definition->columns === [
                'period' => 'Period',
                'orders' => 'Orders',
                'subtotal' => 'Subtotal',
                'discount' => 'Discount',
                'shipping_cost' => 'Shipping Cost',
                'total' => 'Total',
            ]
            && ! array_key_exists('report_row_key', $definition->columns))
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    $component
        ->assertSee('Bulk Actions')
        ->call('exportSelected', $format)
        ->assertFileDownloaded("orders-selected.{$extension}");
})->with([
    'Excel' => ['excel', 'downloadExcel', 'xlsx'],
    'CSV' => ['csv', 'downloadCsv', 'csv'],
    'PDF' => ['pdf', 'downloadPdf', 'pdf'],
]);

test('revenue detail page export follows the active search and sort order', function (): void {
    foreach ([
        ['2026-10-05 12:00:00', '100000.00'],
        ['2026-10-06 12:00:00', '200000.00'],
        ['2026-10-04 12:00:00', '500000.00'],
    ] as [$dateTime, $total]) {
        $order = Order::factory()->create([
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'shipping_cost' => '0.00',
            'total' => $total,
        ]);
        createReportPayment($order, [
            'amount' => $total,
            'status' => PaymentStatus::Paid,
            'verified_at' => reportsDateTime($dateTime),
        ]);
    }

    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    giveReportsPermission($user, 'Export:Order');
    giveReportsPermission($user, 'Export:Payment');
    $user->assignRole(Role::findOrCreate('reports_detail_exporter', 'web'));
    $this->actingAs($user, 'web');

    $response = response()->streamDownload(static fn () => print 'filtered', 'orders-filtered.csv');
    $service = Mockery::mock(TableExportService::class);
    $service->shouldReceive('downloadCsv')
        ->once()
        ->withArgs(fn (Collection $records, string $model, TableExportDefinition $definition): bool => $records->pluck('period')->all() === ['06 Oct 2026', '05 Oct 2026']
            && $model === Order::class
            && ! array_key_exists('report_row_key', $definition->columns))
        ->andReturn($response);
    app()->instance(TableExportService::class, $service);

    Livewire::withQueryParams([
        'startDate' => '2026-10-05',
        'endDate' => '2026-10-06',
        'grouping' => 'daily',
    ])->test(SalesRevenueTrendDetail::class)
        ->set('search', 'Oct 2026')
        ->call('sortBy', 'total')
        ->call('sortBy', 'total')
        ->call('export', 'csv')
        ->assertFileDownloaded('orders-filtered.csv');
});

test('reports only display sections allowed by the users permissions', function (): void {
    $paidOrder = Order::factory()->create();
    createReportPayment($paidOrder, [
        'amount' => '75000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => reportsDateTime('2026-10-05 12:00:00'),
    ]);

    $paymentUser = User::factory()->sso()->create();
    giveReportsPermission($paymentUser, 'ViewAny:Payment');
    $this->actingAs($paymentUser, 'web');

    Livewire::test(Reports::class)
        ->assertSee('Payment Breakdown')
        ->assertSee('Total Sales')
        ->assertDontSee('Total Orders')
        ->assertDontSee('Top Selling Items')
        ->assertDontSee('Daily Reports')
        ->assertDontSee('Sales Reports Filters');

    $paymentOnlyTrend = Livewire::test(Reports::class)
        ->set('startDate', '2026-10-05')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports')
        ->instance()
        ->revenueTrendRows();
    expect($paymentOnlyTrend)->toBe([
        ['label' => '05 Oct 2026', 'amount' => 75000.0],
    ]);

    $orderUser = User::factory()->sso()->create();
    giveReportsPermission($orderUser, 'ViewAny:Order');
    $this->actingAs($orderUser, 'web');

    Livewire::test(Reports::class)
        ->assertSee('Total Orders')
        ->assertSee('Done Orders')
        ->assertDontSee('Payment Breakdown')
        ->assertDontSee('Amount Trend');

    Livewire::test(Reports::class)
        ->set('startDate', '2026-10-01')
        ->set('endDate', '2026-10-02')
        ->call('previewSalesReports')
        ->assertSet('salesPeriod', ['start' => '2026-10-01', 'end' => '2026-10-02']);
});

test('sales filters reject missing and reversed dates and constrain trend grouping', function (): void {
    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    Livewire::test(Reports::class)
        ->set('startDate', '')
        ->set('endDate', '')
        ->call('previewSalesReports')
        ->assertHasErrors(['startDate' => 'required', 'endDate' => 'required'])
        ->set('startDate', '2026-10-06')
        ->set('endDate', '2026-10-05')
        ->call('previewSalesReports')
        ->assertHasErrors(['endDate'])
        ->set('endDate', '2026-10-06')
        ->call('previewSalesReports')
        ->set('revenueGrouping', 'weekly')
        ->assertSet('revenueGrouping', 'weekly')
        ->assertSee('wire:model.live="revenueGrouping"', false)
        ->assertDontSee('wire:submit="previewRevenueTrend"', false)
        ->assertSee('wire:submit="previewSalesReports"', false);
});

test('product report exports require order export permission and preserve the legacy sales reports route', function (): void {
    $user = User::factory()->sso()->create();
    giveReportsPermission($user, 'ViewAny:Order');
    giveReportsPermission($user, 'ViewAny:Payment');
    $this->actingAs($user, 'web');

    Livewire::test(Reports::class)
        ->assertDontSee('Export CSV')
        ->assertDontSee('Bulk Actions')
        ->call('exportProductSales', 'csv')
        ->assertForbidden();

    Livewire::test(Reports::class)
        ->call('exportSelectedProductSales', 'csv')
        ->assertForbidden();

    $user->givePermissionTo(Permission::findOrCreate('Export:Order', 'web'));
    $exportTime = Carbon::parse('2026-10-07 12:00:00', 'Asia/Jakarta');
    Carbon::setTestNow($exportTime);
    try {
        $expectedFilename = 'orders-'.$exportTime->format('Ymd-His').'.csv';
        Livewire::test(Reports::class)
            ->assertSee('Export Excel')
            ->assertSee('Export CSV')
            ->assertSee('Bulk Actions')
            ->call('exportProductSales', 'csv')
            ->assertFileDownloaded($expectedFilename);
    } finally {
        Carbon::setTestNow();
    }

    $legacyUser = User::factory()->sso()->create();
    $legacyUser->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($legacyUser, 'web');
    $this->get('/admin/sales-reports')
        ->assertOk()
        ->assertSee('Sales Reports')
        ->assertSeeInOrder(['sales-reports-date-field', 'sales-reports-date-field'], false)
        ->assertSeeInOrder(['type="date"', 'type="date"'], false);

    Livewire::test(SalesReports::class)
        ->set('data.start', '2026-10-01')
        ->set('data.end', '2026-10-07')
        ->call('loadPayments')
        ->assertHasNoErrors()
        ->assertSet('period', ['start' => '2026-10-01', 'end' => '2026-10-07']);

    expect(SalesReports::getUrl())->toEndWith('/admin/sales-reports');
});
