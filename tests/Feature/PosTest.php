<?php

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Enums\ShippingLocation;
use App\Filament\Pages\Pos;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SystemFreezeState;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AdminOrderCreationService;
use App\Services\EscPosReceipt;
use App\Services\InvoiceGenerationService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function (): void {
    Warehouse::query()->where('code', 'WH-SCCR')->update(['is_pos' => true]);
    $this->actor = User::factory()->sso()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

function posCheckoutData(): array
{
    $customer = Customer::factory()->create();

    return [
        'customer_id' => $customer->id,
        'recipient_name' => $customer->name,
        'recipient_phone' => $customer->phone,
        'shipping_method' => 'pickup',
        'shipping_address' => ShippingLocation::AgungFarmAndGarden->value,
        'shipping_cost' => '0',
        'payment_method' => 'cash',
        'payment_received' => true,
    ];
}

test('POS opens the customer modal when checkout is requested without a saved customer', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->assertActionDisabled('checkout')
        ->mountAction('checkout')->assertActionNotMounted('checkout');

    $component->call('openCheckout', [['id' => $variant->id, 'quantity' => 1, 'price' => '25000.00']])
        ->assertHasNoErrors()->assertActionMounted('customer')
        ->assertDontSee('Select a customer before checkout.')
        ->assertSet('cart.'.$variant->id, 1);

    expect(Order::count())->toBe(0);
});

test('POS customer changes apply only after saving and survive cart changes and checkout cancellation', function (): void {
    $data = posCheckoutData();
    $other = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $component = Livewire::test(Pos::class)->callAction('customer', $data)
        ->assertHasNoActionErrors()->assertSee($data['recipient_name'])->assertDontSee('Edit Customer')
        ->mountAction('customer')->setActionData(['customer_id' => $other->id])
        ->unmountAction()->assertSet('customerData.customer_id', $data['customer_id'])
        ->call('addVariant', $variant->id)->call('setQuantity', $variant->id, 2)
        ->assertActionEnabled('checkout')->mountAction('checkout')->unmountAction()
        ->assertSet('customerData.recipient_phone', $data['recipient_phone']);

    $component->mountAction('customer')->setActionData(['customer_id' => $other->id])
        ->callMountedAction()->assertHasNoActionErrors()
        ->assertSet('customerData.customer_id', $other->id)
        ->assertSet('customerData.recipient_phone', $other->phone);
});

test('POS customer modal only shows the transaction date when backdate is enabled', function (): void {
    $component = Livewire::test(Pos::class)->mountAction('customer');
    $schema = fn (): string => $component->instance()
        ->getSchema($component->instance()->getMountedActionSchemaName())->toHtml();

    expect($schema())->not->toContain('Transaction Date');

    $component->setActionData(['backdate_enabled' => true]);
    expect($schema())->toContain('Transaction Date');

    $component->setActionData(['backdate_enabled' => false]);
    expect($schema())->not->toContain('Transaction Date');
});

test('POS rejects an unavailable customer after saving and before creating an order', function (): void {
    $data = posCheckoutData();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $data)->mountAction('checkout')->setActionData($data);
    Customer::findOrFail($data['customer_id'])->update(['is_active' => false]);

    $component->callMountedAction()->assertHasActionErrors(['customer'])
        ->assertSet('cart.'.$variant->id, 1);
    expect(Order::count())->toBe(0);

    $component->unmountAction()
        ->call('openCheckout', [['id' => $variant->id, 'quantity' => 1, 'price' => '25000.00']])
        ->assertHasNoErrors()->assertActionMounted('customer');
});

test('POS uses saved recipient details without changing the customer profile and resets them after success', function (): void {
    $data = posCheckoutData();
    $customer = Customer::findOrFail($data['customer_id']);
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', array_replace($data, ['recipient_name' => 'Delivery Recipient', 'recipient_phone' => '08123456789']))
        ->callAction('checkout', $data)->assertHasNoActionErrors()
        ->assertSet('customerData', [])->assertSet('cart', [])
        ->assertActionDisabled('checkout');

    expect(Order::sole()->recipient_name)->toBe('Delivery Recipient');
    expect(Order::sole()->recipient_phone)->toBe('08123456789');
    expect($customer->fresh()->name)->toBe($customer->name);
    expect($customer->fresh()->phone)->toBe($customer->phone);
});

test('POS cannot save customer details without a customer', function (): void {
    Livewire::test(Pos::class)->callAction('customer', [])
        ->assertHasActionErrors(['customer_id' => 'required'])
        ->assertSet('customerData', []);
});

test('POS checkout displays saved customer details and accepts an optional recipient phone', function (?string $phone): void {
    $data = array_replace(posCheckoutData(), ['recipient_name' => 'POS Delivery Recipient', 'recipient_phone' => $phone]);
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);

    $component = Livewire::test(Pos::class)
        ->callAction('customer', $data)->assertHasNoActionErrors()
        ->call('openCheckout', [['id' => $variant->id, 'quantity' => 1, 'price' => '25000.00']])
        ->assertActionMounted('checkout');

    $checkoutHtml = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->toHtml();
    expect($checkoutHtml)->toContain('Recipient Name', 'POS Delivery Recipient', 'Recipient Phone', e(Customer::findOrFail($data['customer_id'])->name));

    if (filled($phone)) {
        expect($checkoutHtml)->toContain($phone);
    }

    $component->setActionData($data)->callMountedAction()->assertHasNoActionErrors();

    expect(Order::sole()->recipient_phone)->toBe(filled($phone) ? $phone : null);
})->with([null, '', '08123456789']);

test('POS customer selection cannot be overwritten by a client state update', function (): void {
    Livewire::test(Pos::class)->set('customerData', posCheckoutData());
})->throws(CannotUpdateLockedPropertyException::class);

test('POS invoice checkout creates an unpaid receivable without confirming or printing payment', function (): void {
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);
    $data = array_replace(posCheckoutData(), [
        'customer_id' => $customer->id,
        'payment_method' => 'invoice',
        'payment_received' => false,
        'print_receipt' => true,
    ]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id);
    $component->callAction('customer', $data);
    $token = $component->get('checkoutToken');

    $component->callAction('checkout', $data)->assertHasNoActionErrors()
        ->assertSet('cart', [])->assertSee('Payment: Unpaid')
        ->assertNotDispatched('farming-print-receipt');

    $order = app(AdminOrderCreationService::class)->create($data + [
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'pos_checkout_token' => $token,
    ], expectedPrices: [$variant->id => '25000.00']);

    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and($order->pos_checkout_token)->toBe($token)
        ->and($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->verified_at)->toBeNull()
        ->and($variant->refresh()->stock_reserved)->toBe('1.000');
});

test('POS invoice checkout requires review when the product price changes', function (): void {
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $data = array_replace(posCheckoutData(), ['customer_id' => $customer->id, 'payment_method' => 'invoice', 'payment_received' => false]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id);
    $variant->update(['price' => 30000]);

    $component->callAction('customer', $data)
        ->callAction('checkout', $data)->assertHasActionErrors(['prices'])
        ->assertSet('prices.'.$variant->id, '30000.00');
    expect(Order::count())->toBe(0);

    $component->callMountedAction()->assertHasNoActionErrors();
    expect(Order::sole()->total)->toBe('30000.00')
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Unpaid);
});

test('POS invoice checkout rejects a customer whose billing type changed', function (): void {
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', $data = array_replace(posCheckoutData(), ['customer_id' => $customer->id]))->mountAction('checkout')
        ->setActionData(array_replace($data, ['payment_method' => 'invoice']));
    $customer->update(['customer_type' => CustomerType::Regular]);

    $component->callMountedAction()->assertHasActionErrors(['payment_method']);

    expect(Order::count())->toBe(0);
});

test('POS combines variants in the cart and supports quantity changes and removal', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);

    Livewire::test(Pos::class)
        ->call('chooseProduct', $variant->product_id)
        ->call('addVariant', $variant->id)
        ->assertSet('cart.'.$variant->id, 2)
        ->assertSet('subtotal', '50000.00')
        ->call('setQuantity', $variant->id, 3)
        ->assertSet('cart.'.$variant->id, 3)
        ->call('removeItem', $variant->id)
        ->assertSet('cart', [])
        ->assertActionDisabled('checkout');
});

test('POS opens variant selection and excludes unavailable choices', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['stock_available' => 5]);
    ProductVariant::factory()->for($product)->create(['stock_available' => 0]);

    Livewire::test(Pos::class)
        ->call('chooseProduct', $product->id)
        ->assertActionMounted('chooseVariant')
        ->setActionData(['variant' => $variant->id])
        ->callMountedAction()
        ->assertSet('cart.'.$variant->id, 1);
});

test('POS catalogs only active saved products and filters by name and category', function (): void {
    $first = Product::factory()->create(['name' => 'Fresh Basil']);
    $second = Product::factory()->create(['name' => 'Cherry Tomatoes']);
    $hidden = Product::factory()->create(['name' => 'Hidden Product', 'is_active' => false]);
    Product::factory()->create(['name' => 'Draft Product', 'record_status' => RecordStatus::Draft]);

    Livewire::test(Pos::class)->assertSee($first->name)->assertSee($second->name)
        ->assertDontSee($hidden->name)->assertDontSee('Draft Product')
        ->set('search', 'basil')->assertSee($first->name)->assertDontSee($second->name)
        ->set('search', '')->set('category', $second->category_id)
        ->assertSee($second->name)->assertDontSee($first->name);
});

test('POS paginates the catalog in groups of 24', function (): void {
    Product::factory()->count(25)->sequence(fn ($sequence): array => ['name' => sprintf('Catalog %02d', $sequence->index)])->create();

    Livewire::test(Pos::class)->assertSee('Catalog 23')->assertDontSee('Catalog 24')
        ->call('gotoPage', 2)->assertSee('Catalog 24')->assertDontSee('Catalog 00')
        ->set('search', 'Catalog 00')->assertSee('Catalog 00');
});

test('POS places out of stock products after available products across pages', function (): void {
    $empty = Product::factory()->create(['name' => 'A Empty']);
    ProductVariant::factory()->for($empty)->create(['stock_available' => 0]);
    $products = Product::factory()->count(24)
        ->sequence(fn ($sequence): array => ['name' => sprintf('Available %02d', $sequence->index)])
        ->has(ProductVariant::factory()->state(['stock_available' => 5]), 'variants')->create();

    $component = Livewire::test(Pos::class);

    expect($component->get('products')->pluck('id')->all())->toBe($products->modelKeys());
    $component->call('gotoPage', 2);
    expect($component->get('products')->pluck('id')->all())->toBe([$empty->id]);
});

test('POS sorts variant choices alphabetically including unavailable variants', function (): void {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['name' => 'Zebra', 'stock_available' => 5]);
    ProductVariant::factory()->for($product)->create(['name' => 'Apple', 'stock_available' => 0]);
    ProductVariant::factory()->for($product)->create(['name' => 'Basil', 'stock_available' => 5]);

    $component = Livewire::test(Pos::class);

    expect($component->get('products')->first()->variants->pluck('name')->all())
        ->toBe(['Apple', 'Basil', 'Zebra']);
});

test('POS creates a paid cash order ready for fulfillment and clears the cart', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $checkoutData = posCheckoutData())
        ->callAction('checkout', $checkoutData)
        ->assertHasNoActionErrors()->assertSet('cart', [])
        ->assertSee('Payment: Paid');

    $order = Order::firstOrFail();
    expect($order->status)->toBe(OrderStatus::ReadyToProcess)
        ->and($order->total)->toBe('25000.00')
        ->and($order->shipping_cost)->toBe('0.00')
        ->and($order->payment->status)->toBe(PaymentStatus::Paid)
        ->and($order->payment->verified_by)->toBe($this->actor->id)
        ->and($order->payment->verified_at)->not->toBeNull()
        ->and($order->created_at->timezone('Asia/Jakarta')->toDateString())->toBe(today('Asia/Jakarta')->toDateString())
        ->and($order->payment->created_at->timezone('Asia/Jakarta')->toDateString())->toBe(today('Asia/Jakarta')->toDateString())
        ->and($order->payment->payment_number)->toStartWith('PAY-'.today('Asia/Jakarta')->format('Ymd').'-')
        ->and($order->items)->toHaveCount(1)
        ->and($variant->refresh()->stock_reserved)->toBe('1.000');
});

test('POS backdates the order payment confirmation and initial status history', function (): void {
    $this->travelTo('2026-10-02 10:30:00');
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $checkoutData = array_replace(posCheckoutData(), [
        'backdate_enabled' => true,
        'backdate_date' => '2026-09-29',
    ]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $checkoutData)
        ->callAction('checkout', $checkoutData)
        ->assertHasNoActionErrors();

    $order = Order::sole();
    expect($order->created_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'))->toBe('2026-09-29 10:30:00')
        ->and($order->order_number)->toStartWith('ORD-20260929-')
        ->and($order->payment->created_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'))->toBe('2026-09-29 10:30:00')
        ->and($order->payment->payment_number)->toStartWith('PAY-20260929-')
        ->and($order->payment->verified_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'))->toBe('2026-09-29 10:30:00')
        ->and($order->statusLogs->every(fn ($log): bool => $log->created_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') === '2026-09-29 10:30:00'))->toBeTrue();
});

test('POS backdates unpaid invoice records without setting payment verification time', function (): void {
    $this->travelTo('2026-10-02 10:30:00');
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $checkoutData = array_replace(posCheckoutData(), [
        'customer_id' => $customer->id,
        'payment_method' => 'invoice',
        'payment_received' => false,
        'backdate_enabled' => true,
        'backdate_date' => '2026-09-29',
    ]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $checkoutData)
        ->callAction('checkout', $checkoutData)
        ->assertHasNoActionErrors();

    $order = Order::sole();
    expect($order->created_at->timezone('Asia/Jakarta')->toDateString())->toBe('2026-09-29')
        ->and($order->payment->created_at->timezone('Asia/Jakarta')->toDateString())->toBe('2026-09-29')
        ->and($order->payment->payment_number)->toStartWith('PAY-20260929-')
        ->and($order->payment->status)->toBe(PaymentStatus::Unpaid)
        ->and($order->payment->verified_at)->toBeNull();
});

test('POS backdates proof payment numbers to the selected transaction date', function (): void {
    Storage::fake('public');
    $this->travelTo('2026-10-02 10:30:00');
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $checkoutData = array_replace(posCheckoutData(), [
        'payment_method' => 'qris',
        'backdate_enabled' => true,
        'backdate_date' => '2026-09-29',
    ]);

    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $checkoutData)
        ->callAction('checkout', $checkoutData)
        ->assertHasActionErrors(['payment_proof' => 'required']);

    $component->setActionData(['payment_proof' => UploadedFile::fake()->image('receipt.png')])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $payment = Payment::sole();

    expect($payment->payment_number)->toStartWith('PAY-20260929-')
        ->and($payment->created_at->timezone('Asia/Jakarta')->toDateString())->toBe('2026-09-29');
});

test('POS backdate rejects today and future dates', function (string $date): void {
    $this->travelTo('2026-10-02 10:30:00');
    $data = array_replace(posCheckoutData(), ['backdate_enabled' => true, 'backdate_date' => $date]);

    Livewire::test(Pos::class)->callAction('customer', $data)
        ->assertHasActionErrors(['backdate_date'])
        ->assertSet('customerData', []);

    expect(Order::count())->toBe(0);
})->with(['2026-10-02', '2026-10-03']);

test('POS requires payment proof and confirms the uploaded proof', function (string $method): void {
    Storage::fake('public');
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $data = array_replace(posCheckoutData(), ['payment_method' => $method]);

    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $data)
        ->callAction('checkout', $data)->assertHasActionErrors(['payment_proof' => 'required']);
    expect(Order::count())->toBe(0);

    $component->setActionData(['payment_proof' => UploadedFile::fake()->image('receipt.png')])
        ->callMountedAction()->assertHasNoActionErrors();

    $payment = Payment::firstOrFail();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->currentProof()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->currentProof()->reviewed_by)->toBe($this->actor->id);
    Storage::disk('public')->assertExists($payment->currentProof()->file_path);
})->with(['qris', 'bank_transfer']);

test('POS requires a valid customer even without payment confirmation', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $data = posCheckoutData();

    Customer::findOrFail($data['customer_id'])->update(['is_active' => false]);
    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $data)
        ->assertHasActionErrors(['customer_id']);
    expect(Order::count())->toBe(0);
});

test('POS delivery includes shipping costs and a custom address', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $data = array_replace(posCheckoutData(), [
        'shipping_method' => 'delivery',
        'shipping_address' => 'Others',
        'shipping_address_other' => 'Jalan Garden 12',
        'shipping_cost' => '5.000',
    ]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', $data)
        ->callAction('checkout', $data)->assertHasNoActionErrors();

    expect(Order::firstOrFail()->shipping_address)->toBe('Jalan Garden 12')
        ->and(Order::firstOrFail()->total)->toBe('30000.00');
});

test('POS refreshes a changed price and requires a second confirmation', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $data = posCheckoutData();
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id);
    $variant->update(['price' => 30000]);

    $component->callAction('customer', $data)
        ->callAction('checkout', $data)->assertHasActionErrors(['prices'])
        ->assertSet('cart.'.$variant->id, 1)->assertSet('prices.'.$variant->id, '30000.00')
        ->assertSee('Rp 30.000');
    expect(Order::count())->toBe(0);

    $component->setActionData(['payment_received' => true])->callMountedAction()->assertHasNoActionErrors();
    expect(Order::firstOrFail()->total)->toBe('30000.00');
});

test('POS rejects a product disabled after it was added to the cart', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id);
    $variant->update(['is_active' => false]);

    $component->callAction('customer', $checkoutData = posCheckoutData())
        ->callAction('checkout', $checkoutData)->assertHasActionErrors(['items'])->assertSet('cart.'.$variant->id, 1);
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0);
});

test('POS rejects quantities beyond stock and detects stock changes at checkout', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 2]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->call('setQuantity', $variant->id, 3)->assertSet('cart.'.$variant->id, 1)
        ->call('setQuantity', $variant->id, 0)->assertHasErrors(['quantity'])
        ->call('setQuantity', $variant->id, 2)->assertSet('cart.'.$variant->id, 2);
    $variant->update(['stock_available' => 0]);
    $variant->warehouseStocks()->update(['stock_available' => 0]);

    $component->callAction('customer', $checkoutData = posCheckoutData())
        ->callAction('checkout', $checkoutData)->assertHasActionErrors(['items']);
    expect(Order::count())->toBe(0);
});

test('POS requires both order creation and payment verification permissions', function (array $permissions, bool $allowed): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('cashier', 'web'));
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    $this->actingAs($user);

    expect(Pos::canAccess())->toBe($allowed);
    $this->get(Pos::getUrl())->assertStatus($allowed ? 200 : 403);
})->with([
    'neither' => [[], false],
    'create only' => [['Create:Order'], false],
    'verify only' => [['Verify:Payment'], false],
    'both' => [['Create:Order', 'Verify:Payment'], true],
]);

test('POS checks permissions again on submit', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', posCheckoutData())->mountAction('checkout')->setActionData(posCheckoutData());
    $this->actor->removeRole('super_admin');

    $component->callMountedAction()->assertForbidden();
    expect(Order::count())->toBe(0);
});

test('POS disables checkout while the system is frozen', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    SystemFreezeState::current()->update(['is_frozen' => true]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)->assertActionDisabled('checkout');
});

test('POS retry uses the original order and never reserves stock twice', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);
    $data = posCheckoutData();
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id);
    $component->callAction('customer', $data);
    $token = $component->get('checkoutToken');
    $component->callAction('checkout', $data)->assertHasNoActionErrors();

    $order = app(AdminOrderCreationService::class)->create($data + [
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        'pos_checkout_token' => $token,
    ], confirmPayment: true, expectedPrices: [$variant->id => '25000.00']);

    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and($order->pos_checkout_token)->toBe($token)
        ->and($variant->refresh()->stock_reserved)->toBe('1.000');
});

test('POS rolls back order payment stock and proof when confirmation fails', function (): void {
    Storage::fake('public');
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'stock_reserved' => 0]);
    $data = array_replace(posCheckoutData(), [
        'payment_method' => 'qris',
        'payment_proof' => UploadedFile::fake()->image('proof.png'),
    ]);

    Event::listen('eloquent.updating: '.Payment::class, function (Payment $payment): void {
        if ($payment->isDirty('status') && $payment->status === PaymentStatus::Paid) {
            throw new RuntimeException('Payment confirmation failed.');
        }
    });

    Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', $data)
        ->callAction('checkout', $data)
        ->assertHasActionErrors(['payment_received'])
        ->assertActionMounted('checkout')->assertSet('cart.'.$variant->id, 1);

    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and($variant->refresh()->stock_reserved)->toBe('0.000')
        ->and($variant->stock_available)->toBe('10.000')
        ->and(Storage::disk('public')->allFiles('payment-proofs'))->toBe([]);
});

test('POS guests must sign in', function (): void {
    auth()->logout();
    $this->get(Pos::getUrl())->assertRedirect(route('filament.admin.auth.login'));
});

test('POS defaults to pickup and updates delivery and customer details', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $customer = Customer::factory()->create();
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)->mountAction('customer')
        ->setActionData(['customer_id' => $customer->id])
        ->assertActionDataSet(['recipient_name' => $customer->name, 'recipient_phone' => $customer->phone])
        ->callMountedAction()->assertHasNoActionErrors()->mountAction('checkout')
        ->assertActionDataSet(['shipping_method' => 'pickup', 'shipping_cost' => '0,00'])
        ->setActionData(['shipping_method' => 'delivery'])
        ->assertActionDataSet(['shipping_address' => null])
        ->setActionData(['shipping_method' => 'pickup'])
        ->assertActionDataSet(['shipping_address' => ShippingLocation::AgungFarmAndGarden->value, 'shipping_cost' => '0']);
});

test('POS allows an empty recipient name', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $checkoutData = array_replace(posCheckoutData(), ['recipient_name' => null]))
        ->callAction('checkout', $checkoutData)
        ->assertHasNoActionErrors();

    expect(Order::firstOrFail()->recipient_name)->toBeNull();
});

test('POS pickup accepts Others and requires the manual address', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $data = array_replace(posCheckoutData(), ['shipping_address' => 'Others']);

    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $data)
        ->callAction('checkout', $data)->assertHasActionErrors(['shipping_address_other' => 'required']);
    expect(Order::count())->toBe(0);

    $component->setActionData(['shipping_address_other' => 'Alternate collection point'])
        ->callMountedAction()->assertHasNoActionErrors();
    expect(Order::firstOrFail()->shipping_address)->toBe('Alternate collection point')
        ->and(Order::firstOrFail()->shipping_cost)->toBe('0.00');
});

test('POS creates and selects a customer without losing cart data', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $customer = Customer::factory()->make();
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->mountAction('customer')
        ->mountAction(TestAction::make('createOption')->schemaComponent('customer_id', 'mountedActionSchema0'))
        ->setActionData(['name' => $customer->name, 'phone' => $customer->phone, 'email' => null, 'address' => 'SCCR', 'is_active' => true])
        ->callMountedAction()->assertHasNoActionErrors()
        ->assertActionMounted('customer')->assertSet('cart.'.$variant->id, 1);

    $created = Customer::where('phone', $customer->phone)->firstOrFail();
    expect($created->record_status)->toBe(RecordStatus::Saved)
        ->and($created->hasLoginAccount())->toBeFalse();
    $component->assertActionDataSet([
        'customer_id' => $created->id, 'recipient_name' => $created->name,
        'recipient_phone' => $created->phone,
    ]);
});

test('POS inline customer creation rejects a duplicate phone', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $existing = Customer::factory()->create();

    Livewire::test(Pos::class)->call('addVariant', $variant->id)->mountAction('customer')
        ->mountAction(TestAction::make('createOption')->schemaComponent('customer_id', 'mountedActionSchema0'))
        ->setActionData(['name' => 'Duplicate customer', 'phone' => $existing->phone, 'address' => 'SCCR'])
        ->callMountedAction()->assertHasActionErrors(['phone']);

    expect(Customer::where('phone', $existing->phone)->count())->toBe(1);
});

test('POS customer creation is hidden without customer create permission', function (): void {
    $user = User::factory()->create();
    foreach (['Create:Order', 'Verify:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    $this->actingAs($user);
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)->mountAction('customer')
        ->assertActionHidden(TestAction::make('createOption')->schemaComponent('customer_id', 'mountedActionSchema0'));
});

test('POS imports a browser cart only at checkout and creates the paid order', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);
    $items = [['id' => $variant->id, 'quantity' => 2, 'price' => '25000.00']];
    $component = Livewire::test(Pos::class)->assertSet('cart', [])
        ->callAction('customer', posCheckoutData())->call('openCheckout', $items)->assertActionMounted('checkout')
        ->assertSet('cart.'.$variant->id, 2)->assertSet('subtotal', '50000.00');
    expect(Order::count())->toBe(0);

    $component->setActionData(posCheckoutData())->callMountedAction()
        ->assertHasNoActionErrors()->assertDispatched('pos-cart-cleared');

    expect(Order::sole()->total)->toBe('50000.00')
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid)
        ->and($variant->refresh()->stock_reserved)->toBe('2.000');
});

test('POS rejects invalid browser cart quantities', function (mixed $quantity): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    Livewire::test(Pos::class)->callAction('customer', posCheckoutData())->call('openCheckout', [['id' => $variant->id, 'quantity' => $quantity, 'price' => '25000.00']])
        ->assertHasErrors(['items.0.quantity'])->assertSet('cart', []);
    expect(Order::count())->toBe(0);
})->with([0, -1, 1.0001, 'invalid', '1000000000000000']);

test('POS rejects duplicate browser cart variants', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $item = ['id' => $variant->id, 'quantity' => 1, 'price' => '25000.00'];
    Livewire::test(Pos::class)->callAction('customer', posCheckoutData())->call('openCheckout', [$item, $item])
        ->assertHasErrors(['items.0.id'])->assertSet('cart', []);
});

test('POS validates browser cart availability before opening checkout', function (bool $active, int $quantity): void {
    $variant = ProductVariant::factory()->create(['is_active' => $active, 'stock_available' => 2]);
    Livewire::test(Pos::class)->callAction('customer', posCheckoutData())->call('openCheckout', [['id' => $variant->id, 'quantity' => $quantity, 'price' => '25000.00']])
        ->assertHasErrors(['items'])->assertSet('cart', []);
})->with([[false, 1], [true, 3]]);

test('POS never accepts a browser supplied price as the sale price', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $component = Livewire::test(Pos::class)
        ->callAction('customer', posCheckoutData())->call('openCheckout', [['id' => $variant->id, 'quantity' => 1, 'price' => '1.00']])
        ->setActionData(posCheckoutData())->callMountedAction()->assertHasActionErrors(['prices'])
        ->assertDispatched('pos-prices-updated', prices: [$variant->id => '25000.00']);
    expect(Order::count())->toBe(0);

    $component->setActionData(['payment_received' => true])->callMountedAction()->assertHasNoActionErrors();
    expect(Order::sole()->total)->toBe('25000.00');
});

test('POS checks freeze and permission before importing the browser cart', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    $items = [['id' => $variant->id, 'quantity' => 1, 'price' => '25000.00']];
    $component = Livewire::test(Pos::class);
    SystemFreezeState::current()->update(['is_frozen' => true]);
    $component->callAction('customer', posCheckoutData())->call('openCheckout', $items)->assertHasErrors(['system_freeze'])->assertSet('cart', []);

    $this->actor->removeRole('super_admin');
    $component->call('openCheckout', $items)->assertForbidden();
});

test('POS defaults to printing a receipt after payment succeeds', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $component = Livewire::test(Pos::class)
        ->callAction('customer', posCheckoutData())->call('openCheckout', [['id' => $variant->id, 'quantity' => 1, 'price' => '25000.00']])
        ->assertActionDataSet(['print_receipt' => true])
        ->setActionData(posCheckoutData())->callMountedAction()->assertHasNoActionErrors();

    $payment = Payment::sole();
    expect($payment->status)->toBe(PaymentStatus::Paid);
    $component->assertDispatched('farming-print-receipt',
        receiptUrl: route('admin.payments.receipt', $payment),
        jobUrl: route('admin.payments.print', $payment),
        certificateUrl: route('admin.payments.print-certificate', $payment),
        rawbtUrl: route('admin.payments.rawbt', $payment),
    );
});

test('POS does not print when the receipt checkbox is unchecked', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $checkoutData = posCheckoutData() + ['print_receipt' => false])
        ->callAction('checkout', $checkoutData)
        ->assertHasNoActionErrors()->assertNotDispatched('farming-print-receipt');
    expect(Payment::sole()->status)->toBe(PaymentStatus::Paid);
});

test('POS does not print when customer validation fails', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);
    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', array_replace(posCheckoutData(), ['customer_id' => null]))
        ->assertHasActionErrors(['customer_id'])->assertNotDispatched('farming-print-receipt');
    expect(Payment::count())->toBe(0);
});

test('POS creates unpaid orders without payment confirmation or proof', function (string $method): void {
    $variant = ProductVariant::factory()->create(['stock_available' => 10, 'stock_reserved' => 0]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', posCheckoutData())->mountAction('checkout')->assertActionDataSet(['payment_received' => false])
        ->setActionData(array_replace(posCheckoutData(), [
            'payment_method' => $method,
            'payment_received' => false,
            'print_receipt' => true,
        ]))->callMountedAction()
        ->assertHasNoActionErrors()->assertSet('cart', [])
        ->assertSee('Payment: Unpaid')->assertNotDispatched('farming-print-receipt');

    expect(Order::count())->toBe(1)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Unpaid)
        ->and(Payment::sole()->verified_at)->toBeNull()
        ->and($variant->refresh()->stock_reserved)->toBe('1.000');
})->with(['cash', 'qris', 'bank_transfer']);

test('POS customer options include customer type', function (): void {
    $regular = Customer::factory()->create(['name' => 'Retail Buyer']);
    $invoice = Customer::factory()->invoice()->create(['name' => 'Invoice Buyer']);
    $variant = ProductVariant::factory()->create(['stock_available' => 10]);

    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)->mountAction('customer');

    expect($component->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('customer_id')->getOptions())
        ->toMatchArray([$regular->id => 'Retail Buyer (Regular)', $invoice->id => 'Invoice Buyer (Invoice)']);
});

test('POS bank transfer proof keeps the discounted amount and retries without reserving twice', function (): void {
    Storage::fake('public');
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10, 'stock_reserved' => 0]);
    $data = array_replace(posCheckoutData(), [
        'payment_method' => 'bank_transfer', 'payment_received' => false,
        'payment_proof' => UploadedFile::fake()->image('transfer.png'),
        'shipping_method' => 'delivery', 'shipping_cost' => '5.000', 'discount_amount' => '2.500',
    ]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id);
    $component->callAction('customer', $data);
    $token = $component->get('checkoutToken');
    $component->callAction('checkout', $data)->assertHasNoActionErrors()->assertNotDispatched('farming-print-receipt');

    $order = app(AdminOrderCreationService::class)->create($data + [
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'pos_checkout_token' => $token,
    ]);

    expect($order->total)->toBe('27500.00');
    expect($order->payment->amount)->toBe('27500.00');
    expect($order->payment->status)->toBe(PaymentStatus::WaitingConfirm);
    expect($variant->refresh()->stock_reserved)->toBe('1.000');
    $this->assertDatabaseCount('orders', 1);
});

test('POS synchronizes discounts in both directions and charges the discounted total', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', posCheckoutData())->mountAction('checkout')
        ->setActionData(array_replace(posCheckoutData(), [
            'shipping_method' => 'delivery', 'shipping_cost' => '5.000',
        ]));

    $component->setActionData(['discount_percent' => '12.50'])
        ->assertActionDataSet(['discount_amount' => '3.125,00'])
        ->setActionData(['discount_amount' => '2.000'])
        ->assertActionDataSet(['discount_percent' => '8.00'])
        ->callMountedAction()->assertHasNoActionErrors();

    $order = Order::sole();
    expect($order->subtotal)->toBe('25000.00');
    expect($order->discount_amount)->toBe('2000.00');
    expect($order->total)->toBe('28000.00');
    expect($order->payment->amount)->toBe('28000.00');
    $this->get(route('admin.payments.receipt', $order->payment))
        ->assertSee('Discount')->assertSee('-Rp 2.000');
    expect(base64_decode(app(EscPosReceipt::class)->data($order->payment)[2]['data']))
        ->toContain('Discount')->toContain('-Rp 2.000');
});

test('POS rejects invalid discounts without creating an order', function (string $field, string $value): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', $checkoutData = array_replace(posCheckoutData(), [$field => $value]))
        ->callAction('checkout', $checkoutData)
        ->assertHasActionErrors([$field]);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
})->with([
    'negative percent' => ['discount_percent', '-1'],
    'excess percent' => ['discount_percent', '101'],
    'negative amount' => ['discount_amount', '-1'],
    'excess amount' => ['discount_amount', '25.001'],
    'invalid amount' => ['discount_amount', 'invalid'],
]);

test('POS allows a full product discount while retaining shipping costs', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', posCheckoutData())->mountAction('checkout')
        ->setActionData(array_replace(posCheckoutData(), ['shipping_method' => 'delivery', 'shipping_cost' => '5.000']))
        ->setActionData(['discount_percent' => '100'])
        ->callMountedAction()->assertHasNoActionErrors();

    expect(Order::sole()->discount_amount)->toBe('25000.00');
    expect(Payment::sole()->amount)->toBe('5000.00');
});

test('order creation rejects discounts exceeding the subtotal at the service boundary', function (): void {
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    $data = posCheckoutData() + [
        'discount_amount' => '25000.01',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
    ];

    expect(fn () => app(AdminOrderCreationService::class)->create($data))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
});

test('POS rounds fractional discounts to cents and can reset them to zero', function (): void {
    $variant = ProductVariant::factory()->create(['price' => '99.99', 'stock_available' => 10]);

    Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', posCheckoutData())->mountAction('checkout')
        ->setActionData(posCheckoutData())
        ->setActionData(['discount_percent' => '12.50'])
        ->assertActionDataSet(['discount_amount' => '12,50'])
        ->setActionData(['discount_amount' => '0'])
        ->assertActionDataSet(['discount_percent' => '0.00'])
        ->callMountedAction()->assertHasNoActionErrors();

    expect(Order::sole()->discount_amount)->toBe('0.00');
    expect(Payment::sole()->amount)->toBe('99.99');
});

test('discounted POS invoice orders retain their discount in invoice generation', function (): void {
    $customer = Customer::factory()->invoice()->create();
    $variant = ProductVariant::factory()->create(['price' => 25000, 'stock_available' => 10]);
    Livewire::test(Pos::class)->call('addVariant', $variant->id)->callAction('customer', $data = array_replace(posCheckoutData(), ['customer_id' => $customer->id]))->mountAction('checkout')
        ->setActionData(array_replace($data, ['payment_method' => 'invoice']))
        ->setActionData(['discount_percent' => '10'])
        ->callMountedAction()->assertHasNoActionErrors();

    $payload = app(InvoiceGenerationService::class)->preview([
        'customer_id' => $customer->id, 'generation_method' => 'by_selected_order',
        'selected_order_ids' => [Order::sole()->id],
        'invoice_date' => today()->toDateString(), 'due_date' => today()->addMonth()->toDateString(),
    ]);

    expect($payload['subtotal'])->toBe('25000.00');
    expect($payload['discount_amount'])->toBe('2500.00');
    expect($payload['total'])->toBe('22500.00');
});
