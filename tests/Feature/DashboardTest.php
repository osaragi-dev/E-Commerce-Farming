<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\DashboardDateRange;
use App\Filament\Widgets\AdminStatsOverview;
use App\Filament\Widgets\LowStockWidget;
use App\Filament\Widgets\OrderPipelineChart;
use App\Filament\Widgets\SalesTrendChart;
use App\Filament\Widgets\StockOverview;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor = User::factory()->sso()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($actor);
});

test('dashboard renders lazy widgets with page filters', function (): void {
    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('fi-loading-section', escape: false);
});

test('dashboard stats use only confirmed payments for paid sales', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    $paidOrder = Order::factory()->create(['status' => OrderStatus::Done]);
    Payment::factory()->for($paidOrder)->create([
        'method' => PaymentMethod::Qris,
        'amount' => '150000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => now(),
    ]);

    $deletedOrder = Order::factory()->create(['status' => OrderStatus::Done]);
    Payment::factory()->for($deletedOrder)->create([
        'amount' => '750000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => now(),
    ]);
    $deletedOrder->delete();

    foreach ([PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm, PaymentStatus::Rejected, PaymentStatus::Void] as $status) {
        $order = Order::factory()->create();
        Payment::factory()->for($order)->create([
            'amount' => '999999.00',
            'status' => $status,
        ]);
    }

    Livewire::test(AdminStatsOverview::class)
        ->assertSee('Paid Sales')
        ->assertSee('Rp 150.000,00')
        ->assertDontSee('Rp 900.000,00')
        ->assertDontSee('Rp 4.149.996,00');
});

test('dashboard shows the total amount of active unpaid payments', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    foreach (['125000.00', '75000.00'] as $amount) {
        $order = Order::factory()->create();
        Payment::factory()->for($order)->create([
            'amount' => $amount,
            'status' => PaymentStatus::Unpaid,
        ]);
    }

    foreach ([
        PaymentStatus::Paid,
        PaymentStatus::WaitingConfirm,
        PaymentStatus::Rejected,
        PaymentStatus::UnpaidCancel,
        PaymentStatus::Void,
    ] as $status) {
        $order = Order::factory()->create();
        Payment::factory()->for($order)->create([
            'amount' => '999999.00',
            'status' => $status,
        ]);
    }

    $deletedOrder = Order::factory()->create();
    Payment::factory()->for($deletedOrder)->create([
        'amount' => '900000.00',
        'status' => PaymentStatus::Unpaid,
    ]);
    $deletedOrder->delete();

    $stats = new class extends AdminStatsOverview
    {
        /** @return array<string, mixed> */
        public function values(): array
        {
            $values = [];

            foreach ($this->getStats() as $stat) {
                $values[$stat->getLabel()] = $stat->getValue();
            }

            return $values;
        }
    };

    $stats->pageFilters = ['date' => '2026-09-11'];
    $selected = $stats->values();
    $stats->pageFilters = ['date' => '2026-09-12'];
    $today = $stats->values();

    expect($selected['Outstanding Payments'])->toBe('Rp 200.000,00')
        ->and($today['Outstanding Payments'])->toBe('Rp 200.000,00');

    Livewire::test(AdminStatsOverview::class)
        ->assertSee('Outstanding Payments')
        ->assertSee('Rp 200.000,00')
        ->assertSee('href="'.PaymentResource::getUrl().'"', escape: false);
});

test('dashboard filters default to today and expose supported controls', function (): void {
    $component = Livewire::test(Dashboard::class)
        ->assertSet('filters.time_range', 'today')
        ->assertActionExists('filters')
        ->mountAction('filters')
        ->assertActionMounted('filters');

    $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());
    $timeRange = $schema->getComponent('time_range');

    expect($timeRange->getLabel())->toBe('Time Range')
        ->and($timeRange->getOptions())->toBe([
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'last_7_days' => 'Last 7 days',
            'last_30_days' => 'Last 30 days',
            'this_week' => 'This week',
            'this_month' => 'This month',
            'custom' => 'Custom range',
        ])
        ->and($schema->getComponent('chart_period'))->toBeNull()
        ->and($component->instance()->filters)->not->toHaveKey('chart_period');

    $component->fillForm(['time_range' => 'last_7_days'])
        ->callMountedAction()
        ->assertSet('filters.time_range', 'last_7_days')
        ->set('filters.time_range', 'unsupported')
        ->assertSet('filters.time_range', 'today');
});

test('custom dashboard filters require valid inclusive dates before submit', function (): void {
    Livewire::test(Dashboard::class)
        ->mountAction('filters')
        ->fillForm(['time_range' => 'custom'])
        ->callMountedAction()
        ->assertHasActionErrors(['start_date' => 'required', 'end_date' => 'required']);

    Livewire::test(Dashboard::class)
        ->mountAction('filters')
        ->fillForm([
            'time_range' => 'custom',
            'start_date' => '2026-09-13',
            'end_date' => '2026-09-12',
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['end_date']);

    Livewire::test(Dashboard::class)
        ->mountAction('filters')
        ->fillForm([
            'time_range' => 'custom',
            'start_date' => '2026-09-11',
            'end_date' => '2026-09-12',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertSet('filters.time_range', 'custom')
        ->assertSet('filters.start_date', '2026-09-11')
        ->assertSet('filters.end_date', '2026-09-12');
});

test('canceling dashboard filters leaves the committed range unchanged', function (): void {
    Livewire::test(Dashboard::class)
        ->mountAction('filters')
        ->fillForm(['time_range' => 'last_7_days'])
        ->unmountAction()
        ->assertSet('filters.time_range', 'today');
});

test('dashboard time ranges use Jakarta calendar dates and inclusive boundaries', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    $ranges = [
        'today' => ['2026-09-12 00:00:00.000000', '2026-09-12 23:59:59.999999'],
        'yesterday' => ['2026-09-11 00:00:00.000000', '2026-09-11 23:59:59.999999'],
        'last_7_days' => ['2026-09-06 00:00:00.000000', '2026-09-12 23:59:59.999999'],
        'last_30_days' => ['2026-08-14 00:00:00.000000', '2026-09-12 23:59:59.999999'],
        'this_week' => ['2026-09-07 00:00:00.000000', '2026-09-12 23:59:59.999999'],
        'this_month' => ['2026-09-01 00:00:00.000000', '2026-09-12 23:59:59.999999'],
    ];

    foreach ($ranges as $preset => [$start, $end]) {
        $range = DashboardDateRange::fromFilters(['time_range' => $preset]);

        expect($range->start->format('Y-m-d H:i:s.u'))->toBe($start)
            ->and($range->end->format('Y-m-d H:i:s.u'))->toBe($end);
    }

    $custom = DashboardDateRange::fromFilters([
        'time_range' => 'custom',
        'start_date' => '2026-09-25',
        'end_date' => '2026-10-01',
    ]);

    expect($custom->start->format('Y-m-d H:i:s'))->toBe('2026-09-25 00:00:00')
        ->and($custom->end->format('Y-m-d H:i:s.u'))->toBe('2026-10-01 23:59:59.999999');
});

test('invalid custom dashboard ranges fall back to today', function (array $filters): void {
    $this->travelTo('2026-09-12 10:00:00');

    $range = DashboardDateRange::fromFilters(['time_range' => 'today']);
    $invalidRange = DashboardDateRange::fromFilters($filters);

    expect($invalidRange->start->equalTo($range->start))->toBeTrue()
        ->and($invalidRange->end->equalTo($range->end))->toBeTrue();
})->with([
    'missing dates' => [['time_range' => 'custom']],
    'invalid date' => [['time_range' => 'custom', 'start_date' => '2026-02-30', 'end_date' => '2026-03-01']],
    'reversed dates' => [['time_range' => 'custom', 'start_date' => '2026-09-13', 'end_date' => '2026-09-12']],
]);

test('dashboard time ranges can be initialized from query parameters', function (): void {
    $component = Livewire::withQueryParams(['filters' => ['time_range' => 'yesterday', 'chart_period' => 'weekly']])
        ->test(Dashboard::class)
        ->assertSet('filters.time_range', 'yesterday');

    expect($component->instance()->filters)->not->toHaveKey('chart_period');
});

test('selected date range filters activity and current state metrics', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    $pastCustomer = Customer::factory()->create(['created_at' => '2026-09-11 00:00:00']);
    $todayCustomer = Customer::factory()->create(['created_at' => '2026-09-12 00:00:00']);

    $olderOrder = Order::factory()->for($pastCustomer)->create([
        'status' => OrderStatus::ReadyToProcess,
        'created_at' => '2026-09-05 23:59:59',
    ]);
    Payment::factory()->for($olderOrder)->create([
        'amount' => '5000.00',
        'status' => PaymentStatus::Unpaid,
        'verified_at' => null,
        'created_at' => '2026-09-05 23:59:59',
    ]);

    $boundaryOrder = Order::factory()->for($pastCustomer)->create([
        'status' => OrderStatus::ReadyToProcess,
        'created_at' => '2026-09-06 00:00:00',
    ]);
    Payment::factory()->for($boundaryOrder)->create([
        'amount' => '50000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => '2026-09-06 00:00:00',
        'created_at' => '2026-09-06 00:00:00',
    ]);

    $paidOrder = Order::factory()->for($pastCustomer)->create([
        'status' => OrderStatus::Packing,
        'created_at' => '2026-09-11 00:00:00',
    ]);
    Payment::factory()->for($paidOrder)->create([
        'amount' => '150000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => '2026-09-11 23:59:59',
        'created_at' => '2026-09-11 00:00:00',
    ]);
    $pendingOrder = Order::factory()->for($pastCustomer)->create(['created_at' => '2026-09-10 10:00:00']);
    Payment::factory()->for($pendingOrder)->create([
        'amount' => '10000.00',
        'status' => PaymentStatus::Unpaid,
        'verified_at' => null,
        'created_at' => '2026-09-11 08:00:00',
    ]);
    $reviewOrder = Order::factory()->for($pastCustomer)->create([
        'status' => OrderStatus::Done,
        'created_at' => '2026-09-05 09:00:00',
    ]);
    Payment::factory()->for($reviewOrder)->create([
        'amount' => '7000.00',
        'status' => PaymentStatus::WaitingConfirm,
        'verified_at' => null,
        'created_at' => '2026-09-11 10:00:00',
    ]);
    $todayOrder = Order::factory()->for($todayCustomer)->create(['created_at' => '2026-09-12 00:00:00']);
    Payment::factory()->for($todayOrder)->create([
        'amount' => '25000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => '2026-09-12 09:00:00',
        'created_at' => '2026-09-12 00:00:00',
    ]);
    $todayPendingOrder = Order::factory()->for($todayCustomer)->create([
        'created_at' => '2026-09-12 08:00:00',
    ]);
    Payment::factory()->for($todayPendingOrder)->create([
        'amount' => '20000.00',
        'status' => PaymentStatus::Unpaid,
        'verified_at' => null,
        'created_at' => '2026-09-12 08:00:00',
    ]);

    $stats = new class extends AdminStatsOverview
    {
        /** @return array<string, mixed> */
        public function values(): array
        {
            $values = [];

            foreach ($this->getStats() as $stat) {
                $values[$stat->getLabel()] = $stat->getValue();
            }

            return $values;
        }
    };

    $stats->pageFilters = [
        'time_range' => 'custom',
        'start_date' => '2026-09-11',
        'end_date' => '2026-09-12',
    ];
    $selected = $stats->values();
    $stats->pageFilters = ['time_range' => 'yesterday'];
    $yesterday = $stats->values();
    $stats->pageFilters = ['time_range' => 'today'];
    $today = $stats->values();
    $stats->pageFilters = ['time_range' => 'last_7_days'];
    $lastSevenDays = $stats->values();
    $stats->pageFilters = [
        'time_range' => 'custom',
        'start_date' => '2026-09-13',
        'end_date' => '2026-09-13',
    ];
    $future = $stats->values();

    expect($selected['Paid Sales'])->toBe('Rp 175.000,00')
        ->and($selected['New Orders'])->toBe(3)
        ->and($selected['Payments to Review'])->toBe(3)
        ->and($selected['Outstanding Payments'])->toBe('Rp 30.000,00')
        ->and($selected['Active Orders'])->toBe(3)
        ->and($yesterday['Paid Sales'])->toBe('Rp 150.000,00')
        ->and($yesterday['New Orders'])->toBe(1)
        ->and($yesterday['Payments to Review'])->toBe(2)
        ->and($yesterday['Outstanding Payments'])->toBe('Rp 10.000,00')
        ->and($yesterday['Active Orders'])->toBe(1)
        ->and($today['Paid Sales'])->toBe('Rp 25.000,00')
        ->and($today['New Orders'])->toBe(2)
        ->and($today['Payments to Review'])->toBe(1)
        ->and($today['Outstanding Payments'])->toBe('Rp 20.000,00')
        ->and($today['Active Orders'])->toBe(2)
        ->and($lastSevenDays['Paid Sales'])->toBe('Rp 225.000,00')
        ->and($lastSevenDays['New Orders'])->toBe(5)
        ->and($lastSevenDays['Payments to Review'])->toBe(3)
        ->and($lastSevenDays['Outstanding Payments'])->toBe('Rp 30.000,00')
        ->and($lastSevenDays['Active Orders'])->toBe(5)
        ->and($future['Paid Sales'])->toBe('Rp 0,00')
        ->and($future['New Orders'])->toBe(0)
        ->and($future['Payments to Review'])->toBe(0)
        ->and($future['Outstanding Payments'])->toBe('Rp 0,00')
        ->and($future['Active Orders'])->toBe(0);

    Livewire::test(AdminStatsOverview::class, ['pageFilters' => [
        'time_range' => 'custom',
        'start_date' => '2026-09-11',
        'end_date' => '2026-09-12',
    ]])
        ->assertSee('Sep 11, 2026');
});

test('sales chart groups single-day ranges by hour and limits today to the current hour', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    $paidOrder = Order::factory()->create(['status' => OrderStatus::Done]);
    Payment::factory()->for($paidOrder)->create([
        'amount' => '125000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => '2026-09-11 23:59:59',
    ]);
    $earlyOrder = Order::factory()->create(['status' => OrderStatus::Done]);
    Payment::factory()->for($earlyOrder)->create([
        'amount' => '75000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => '2026-09-11 00:00:00',
    ]);
    $nextDayOrder = Order::factory()->create(['status' => OrderStatus::Done]);
    Payment::factory()->for($nextDayOrder)->create([
        'amount' => '50000.00',
        'status' => PaymentStatus::Paid,
        'verified_at' => '2026-09-12 00:00:00',
    ]);

    $unpaidOrder = Order::factory()->create();
    Payment::factory()->for($unpaidOrder)->create([
        'amount' => '900000.00',
        'status' => PaymentStatus::Unpaid,
        'verified_at' => '2026-09-11 23:59:59',
    ]);

    $chart = new class extends SalesTrendChart
    {
        /** @return array<string, mixed> */
        public function data(): array
        {
            return $this->getData();
        }
    };

    $chart->pageFilters = ['time_range' => 'yesterday'];
    $data = $chart->data();
    $yesterdayDescription = $chart->getDescription();
    $chart->pageFilters = [
        'time_range' => 'custom',
        'start_date' => '2026-09-12',
        'end_date' => '2026-09-12',
    ];
    $nextDay = $chart->data();
    $chart->pageFilters = ['time_range' => 'today'];
    $today = $chart->data();

    expect($data['labels'])->toHaveCount(24)
        ->and($data['labels'][0])->toBe('00:00')
        ->and($data['labels'][23])->toBe('23:00')
        ->and($data['datasets'][0]['data'][0])->toBe(75000.0)
        ->and($data['datasets'][0]['data'][23])->toBe(125000.0)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(200000.0)
        ->and($yesterdayDescription)->toBe('Active filter: Yesterday')
        ->and($nextDay['labels'])->toHaveCount(11)
        ->and($nextDay['labels'][10])->toBe('10:00')
        ->and($nextDay['datasets'][0]['data'][0])->toBe(50000.0)
        ->and($today['labels'])->toBe($nextDay['labels'])
        ->and($today['datasets'][0]['data'])->toBe($nextDay['datasets'][0]['data'])
        ->and($chart->getDescription())->toBe('Active filter: Today');
});

test('sales chart groups last seven days by date regardless of legacy chart period', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    foreach ([
        ['2026-08-30 12:00:00', '90000.00'],
        ['2026-08-31 10:00:00', '100000.00'],
        ['2026-09-06 12:00:00', '50000.00'],
        ['2026-09-07 10:00:00', '200000.00'],
        ['2026-09-12 12:00:00', '300000.00'],
    ] as [$verifiedAt, $amount]) {
        $order = Order::factory()->create();
        Payment::factory()->for($order)->create([
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'verified_at' => $verifiedAt,
        ]);
    }

    $chart = new class extends SalesTrendChart
    {
        /** @return array<string, mixed> */
        public function data(): array
        {
            return $this->getData();
        }
    };
    $chart->pageFilters = [
        'time_range' => 'last_7_days',
        'chart_period' => 'weekly',
    ];

    $data = $chart->data();

    expect($data['labels'])->toBe([
        'Sep 06, 2026',
        'Sep 07, 2026',
        'Sep 08, 2026',
        'Sep 09, 2026',
        'Sep 10, 2026',
        'Sep 11, 2026',
        'Sep 12, 2026',
    ])
        ->and($data['datasets'][0]['data'])->toBe([50000.0, 200000.0, 0.0, 0.0, 0.0, 0.0, 300000.0])
        ->and($chart->getDescription())->toBe('Active filter: Last 7 days');
});

test('sales chart groups custom multi-day ranges by date', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    foreach ([
        ['2026-09-11 23:59:59', '125000.00'],
        ['2026-09-12 00:00:00', '50000.00'],
    ] as [$verifiedAt, $amount]) {
        $order = Order::factory()->create();
        Payment::factory()->for($order)->create([
            'amount' => $amount,
            'status' => PaymentStatus::Paid,
            'verified_at' => $verifiedAt,
        ]);
    }

    $chart = new class extends SalesTrendChart
    {
        /** @return array<string, mixed> */
        public function data(): array
        {
            return $this->getData();
        }
    };
    $chart->pageFilters = [
        'time_range' => 'custom',
        'start_date' => '2026-09-11',
        'end_date' => '2026-09-12',
        'chart_period' => 'weekly',
    ];

    $data = $chart->data();

    expect($data['labels'])->toBe(['Sep 11, 2026', 'Sep 12, 2026'])
        ->and($data['datasets'][0]['data'])->toBe([125000.0, 50000.0])
        ->and($chart->getDescription())->toBe('Active filter: Custom range (Sep 11, 2026 - Sep 12, 2026)');
});

test('sales chart uses K for thousands on the y axis', function (): void {
    $chart = new class extends SalesTrendChart
    {
        public function options(): string
        {
            return $this->getOptions()->toHtml();
        }
    };

    expect($chart->options())
        ->toContain(".format(value).replace(/\\s*rb$/, ' K')")
        ->toContain("currency: 'IDR'");
});

test('order pipeline filters order creation dates while grouping current status', function (): void {
    $this->travelTo('2026-09-12 10:00:00');

    Order::factory()->create([
        'status' => OrderStatus::WaitingConfirmation,
        'created_at' => '2026-09-12 08:00:00',
    ]);
    Order::factory()->create([
        'status' => OrderStatus::ReadyToProcess,
        'created_at' => '2026-09-06 00:00:00',
    ]);
    Order::factory()->create([
        'status' => OrderStatus::Done,
        'created_at' => '2026-09-11 23:59:59.999999',
    ]);
    Order::factory()->create([
        'status' => OrderStatus::Cancelled,
        'created_at' => '2026-09-12 00:00:00',
    ]);
    Order::factory()->create([
        'status' => OrderStatus::Packing,
        'created_at' => '2026-09-12 23:59:59.999999',
    ]);
    Order::factory()->create([
        'status' => OrderStatus::Shipping,
        'created_at' => '2026-09-13 00:00:00',
    ]);
    Order::factory()->create([
        'status' => OrderStatus::WaitingConfirmation,
        'created_at' => '2026-09-05 23:59:59.999999',
    ]);

    $chart = new class extends OrderPipelineChart
    {
        /** @return array<string, mixed> */
        public function data(): array
        {
            return $this->getData();
        }
    };

    $chart->pageFilters = ['time_range' => 'today'];
    $today = $chart->data();
    $chart->pageFilters = ['time_range' => 'yesterday'];
    $yesterday = $chart->data();
    $chart->pageFilters = ['time_range' => 'last_7_days'];
    $data = $chart->data();
    $readyIndex = array_search('Ready to Process', $data['labels'], true);
    $doneIndex = array_search('Done', $data['labels'], true);
    $cancelledIndex = array_search('Cancelled', $data['labels'], true);

    expect($readyIndex)->toBeInt()
        ->and($doneIndex)->toBeInt()
        ->and($cancelledIndex)->toBeInt()
        ->and($data['labels'])->toHaveCount(6)
        ->and($today['datasets'][0]['data'])->toBe([0, 1, 1, 0, 0, 1])
        ->and($yesterday['datasets'][0]['data'])->toBe([0, 0, 0, 0, 1, 0])
        ->and($data['datasets'][0]['data'])->toBe([0, 2, 1, 0, 1, 1])
        ->and($data['datasets'][0]['backgroundColor'][$readyIndex])->toBe('#64748b')
        ->and($data['datasets'][0]['backgroundColor'][$doneIndex])->toBe('#16a34a');
});

test('dashboard widgets hide domain data when the user lacks the matching permission', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate('ViewAny:Order', 'web');
    $user->givePermissionTo('ViewAny:Order');
    $this->actingAs($user);

    expect(AdminStatsOverview::canView())->toBeTrue()
        ->and(SalesTrendChart::canView())->toBeFalse()
        ->and(StockOverview::canView())->toBeFalse()
        ->and(LowStockWidget::canView())->toBeFalse();

    Livewire::test(AdminStatsOverview::class)
        ->assertSee('New Orders')
        ->assertDontSee('Paid Sales')
        ->assertDontSee('Payments to Review')
        ->assertDontSee('Outstanding Payments');
});

test('admin panel registers widgets while the dashboard omits stock widgets and account card', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(Dashboard::class);
    expect(config('filament-shield.pages.exclude'))->toContain(Dashboard::class);

    expect(Filament::getPanel('admin')->getWidgets())
        ->not->toContain(AccountWidget::class)
        ->toContain(AdminStatsOverview::class)
        ->toContain(SalesTrendChart::class)
        ->toContain(OrderPipelineChart::class)
        ->toContain(StockOverview::class)
        ->toContain(LowStockWidget::class)
        ->not->toContain('App\\Filament\\Widgets\\OperationsTable');

    expect(app(Dashboard::class)->getWidgets())
        ->not->toContain(StockOverview::class)
        ->not->toContain(LowStockWidget::class);
});

test('dashboard navigation cards render their resource destinations', function (): void {
    Livewire::test(AdminStatsOverview::class)
        ->assertSee('href="'.PaymentResource::getUrl().'"', escape: false)
        ->assertSee('href="'.OrderResource::getUrl().'"', escape: false)
        ->assertDontSee('New Customers');
});

test('dashboard charts render in separate full-width rows', function (): void {
    expect((new SalesTrendChart)->getColumnSpan())->toBe('full')
        ->and((new OrderPipelineChart)->getColumnSpan())->toBe('full');
});
