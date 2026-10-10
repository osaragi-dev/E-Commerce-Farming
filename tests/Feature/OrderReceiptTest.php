<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Orders\Tables\Actions\PrintReceiptAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\EscPosReceipt;
use Livewire\Livewire;

beforeEach(function (): void {
    $superAdministrator = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->sso()->create();
    $this->actor->assignRole($superAdministrator);
    $this->actingAs($this->actor);
});

test('order printing dispatches the shared printer flow without a navigation link', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::Packing]);
    $action = PrintReceiptAction::make()->record($order);

    expect($action->getUrl())->toBeNull()
        ->and($action->getAlpineClickHandler())->toContain('farming-print-receipt', $order->id, 'receiptUrl', 'jobUrl', 'certificateUrl', 'rawbtUrl');
    $this->get(OrderResource::getUrl())->assertOk()->assertSee('data-printer-controls', false);
});

test('all order receipt formats preserve the original order snapshot', function (): void {
    config(['qz.certificate' => '', 'qz.private_key' => '']);
    $order = Order::factory()->create([
        'recipient_name' => 'Receipt Recipient', 'recipient_phone' => '081234567890',
        'shipping_method' => ShippingMethod::Delivery, 'shipping_address' => 'Receipt delivery address',
        'subtotal' => 50000, 'discount_amount' => 5000, 'shipping_cost' => 10000, 'total' => 55000,
    ]);
    Payment::factory()->for($order)->create(['method' => PaymentMethod::Cash]);
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Receipt Eggs', 'variant_name' => 'Grade A', 'unit' => 'pcs',
        'quantity' => 2, 'unit_price' => 25000, 'subtotal' => 50000,
    ]);

    $html = $this->get(route('admin.orders.receipt', [$order, 'embedded' => 1]))->assertOk()
        ->assertDontSee('data-print-receipt', false)->getContent();
    $job = $this->postJson(route('admin.orders.print', $order))->assertOk()->json();
    $raw = base64_decode($this->postJson(route('admin.orders.rawbt', $order))->assertOk()->json('data'));
    $text = base64_decode($job['params']['data'][2]['data']);

    foreach ([$html, $text, $raw] as $receipt) {
        expect($receipt)->toContain($order->order_number, 'Receipt Recipient', '081234567890',
            'Receipt delivery address', 'Delivery', 'Cash', 'Grade A', '2 pcs',
            'Rp 25.000', 'Rp 50.000', 'Rp 5.000', 'Rp 10.000', 'Rp 55.000',
            $order->created_at->timezone('Asia/Jakarta')->format('d M Y H:i'))
            ->not->toContain('Receipt Eggs', ',00', ' / 1 pcs');
    }
});

test('receipts only show nonzero discount and shipping amounts', function (int $discount, int $shipping): void {
    $order = Order::factory()->create(['discount_amount' => $discount, 'shipping_cost' => $shipping]);
    $payment = Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid]);

    foreach (['orders' => $order, 'payments' => $payment] as $resource => $record) {
        $html = $this->get(route('admin.'.$resource.'.receipt', $record))->assertOk()->getContent();
        $data = app(EscPosReceipt::class)->data($record);
        $text = base64_decode($data[2]['data']);

        expect(str_contains($html, '<dt>Discount</dt>'))->toBe($discount > 0)
            ->and(str_contains($html, '<dt>Shipping</dt>'))->toBe($shipping > 0)
            ->and((bool) preg_match('/^Discount\s/m', $text))->toBe($discount > 0)
            ->and((bool) preg_match('/^Shipping\s+Rp/m', $text))->toBe($shipping > 0);
    }
})->with([[0, 0], [5000, 0], [0, 10000], [5000, 10000]]);

test('order printing endpoints require order view permission', function (): void {
    $order = Order::factory()->create();
    $unauthorized = User::factory()->sso()->create();
    $unauthorized->assignRole(Role::findOrCreate('printer_operator', 'web'));
    $this->actingAs($unauthorized);

    $this->get(route('admin.orders.receipt', $order))->assertForbidden();
    $this->getJson(route('admin.orders.print-certificate', $order))->assertForbidden();
    $this->postJson(route('admin.orders.print', $order))->assertForbidden();
    $this->postJson(route('admin.orders.rawbt', $order))->assertForbidden();
});

test('admin orders label the view action as View', function (): void {
    $order = Order::factory()->create();

    Livewire::test(ManageOrders::class)
        ->assertTableActionHasLabel('view', 'View', $order);
});

test('order detail shows product and variant columns in order', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Fresh Eggs',
        'variant_name' => 'Grade A',
        'unit' => 'pcs',
    ]);

    $component = Livewire::test(ManageOrders::class)
        ->mountTableAction('view', $order)
        ->assertActionMounted();

    $modal = $component->getMountedActionModalHtml();

    expect($modal)
        ->toContain('Product', 'Variant', 'Qty', 'Unit', 'Price', 'Subtotal', 'Fresh Eggs', 'Grade A', 'pcs')
        ->and(strpos($modal, 'Product'))->toBeLessThan(strpos($modal, 'Variant'));
});

test('order detail uses the same displayed status as the orders table', function (): void {
    $order = Order::factory()->create(['status' => OrderStatus::WaitingConfirmation]);

    $modal = Livewire::test(ManageOrders::class)
        ->mountTableAction('view', $order)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modal)
        ->toContain('Ready to Process')
        ->not->toContain('Waiting Confirmation');
});

test('order detail modal shows method-specific shipping information', function (ShippingMethod $shippingMethod, string $locationLabel, string $address): void {
    $order = Order::factory()->create([
        'shipping_method' => $shippingMethod,
        'shipping_address' => $address,
    ]);

    $modal = Livewire::test(ManageOrders::class)
        ->mountTableAction('view', $order)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modal)
        ->toContain('Shipping Information', 'Shipping Method', $shippingMethod->getLabel(), $locationLabel, e($address))
        ->not->toContain('Delivery Information');
})->with([
    'delivery' => [ShippingMethod::Delivery, 'Delivery Address', 'SCCR'],
    'pickup' => [ShippingMethod::Pickup, 'Pickup Location', 'Agung Farm & Garden'],
]);

test('print receipt is available from packing through done', function (OrderStatus $status): void {
    $order = Order::factory()->create(['status' => $status]);

    Livewire::test(ManageOrders::class)
        ->assertTableActionVisible('printReceipt', $order);
})->with([
    'packing' => OrderStatus::Packing,
    'shipping' => OrderStatus::Shipping,
    'done' => OrderStatus::Done,
]);

test('print receipt is hidden before packing and after cancellation', function (OrderStatus $status): void {
    $order = Order::factory()->create(['status' => $status]);

    Livewire::test(ManageOrders::class)
        ->assertTableActionHidden('printReceipt', $order);
})->with([
    'waiting payment' => OrderStatus::WaitingPayment,
    'ready to process' => OrderStatus::ReadyToProcess,
    'cancelled' => OrderStatus::Cancelled,
]);

test('order receipt shows only the variant snapshot', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Fresh Eggs',
        'variant_name' => 'Grade A',
    ]);

    $this->get(route('admin.orders.receipt', $order))
        ->assertOk()
        ->assertDontSee('Fresh Eggs')
        ->assertSee('<div class="item-name">Grade A</div>', false);
});

test('order receipt shows a placeholder when an item has no variant snapshot', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['variant_name' => null]);

    $this->get(route('admin.orders.receipt', $order))
        ->assertOk()
        ->assertSee('<div class="item-name">—</div>', false);
});

test('order receipt uses the payment thermal layout without clipping items', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Fresh Eggs',
        'variant_name' => 'Grade A',
        'quantity' => 2,
        'unit_price' => 25000,
        'subtotal' => 50000,
    ]);

    $response = $this->get(route('admin.orders.receipt', $order))->assertOk();
    $html = $response->getContent();

    expect($html)
        ->toContain('<table')
        ->toContain('Grade A')
        ->toContain('@page')
        ->toContain('width: 80mm')
        ->not->toContain('overflow: hidden;')
        ->not->toContain('Order details')
        ->not->toContain('Your order, at a glance.');
});

test('order receipt uses the shared monochrome thermal style', function (): void {
    $order = Order::factory()->create();

    $this->get(route('admin.orders.receipt', $order))
        ->assertSuccessful()
        ->assertSee('class="receipt"', false)
        ->assertSee('Print Receipt', false)
        ->assertSee('Arial, sans-serif', false)
        ->assertSee('@media print', false)
        ->assertSee('background: #fff', false)
        ->assertDontSee('brand-mark', false);
});

test('order receipt aligns totals amounts to the right', function (): void {
    $order = Order::factory()->create();

    $this->get(route('admin.orders.receipt', $order))
        ->assertSuccessful()
        ->assertSee('dd { margin: 0; text-align: right; }', false)
        ->assertSee('text-align: right;', false)
        ->assertSee('class="total"', false);
});

test('order receipt displays the same thermal logo as payments', function (): void {
    $order = Order::factory()->create();

    $this->get(route('admin.orders.receipt', $order))
        ->assertSuccessful()
        ->assertSee('images/logo/agung-farm-receipt-logo.png', false)
        ->assertDontSee('images/logo/agung-farm-logo.png', false)
        ->assertSee('alt="Agung Farm &amp; Garden"', false);
});

test('order receipt shows method-specific shipping information', function (ShippingMethod $shippingMethod, string $locationLabel, string $address): void {
    $order = Order::factory()->create([
        'shipping_method' => $shippingMethod,
        'shipping_address' => $address,
    ]);

    $this->get(route('admin.orders.receipt', $order))
        ->assertSuccessful()
        ->assertSee('Shipping Method')
        ->assertSee($shippingMethod->getLabel())
        ->assertSee($locationLabel)
        ->assertSee($address);
})->with([
    'delivery' => [ShippingMethod::Delivery, 'Delivery Address', 'SCCR'],
    'pickup' => [ShippingMethod::Pickup, 'Pickup Location', 'Agung Farm & Garden'],
]);
