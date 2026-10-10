<?php

use App\Enums\StockMutationSource;
use App\Filament\Pages\Pos;
use App\Livewire\Public\OrderHistory;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\AdminOrderCreationService;
use App\Support\VariantPricing;
use Livewire\Livewire;

beforeEach(function (): void {
    Warehouse::query()->where('code', 'WH-SCCR')->update(['is_pos' => true]);
    $this->actor = User::factory()->sso()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

test('admin orders price decimal quantities proportionally and preserve their snapshot', function (string $quantity, string $subtotal): void {
    $customer = Customer::factory()->create();
    $variant = ProductVariant::factory()->create(['price' => '20000', 'price_quantity' => '1.5', 'stock_available' => '10']);
    $order = app(AdminOrderCreationService::class)->create([
        'customer_id' => $customer->id,
        'recipient_name' => $customer->name,
        'recipient_phone' => $customer->phone,
        'shipping_method' => 'pickup',
        'payment_method' => 'cash',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => $quantity]],
    ]);

    expect($order->subtotal)->toBe($subtotal);
    expect($order->items->sole()->price_quantity)->toBe('1.500');
    $variant->update(['price_quantity' => '2', 'price' => '30000']);
    expect($order->fresh()->items->sole()->price_quantity)->toBe('1.500');
    expect($order->fresh()->subtotal)->toBe($subtotal);
})->with([['1.5', '20000.00'], ['1,6', '21333.33'], ['0.125', '1666.67']]);

test('POS accepts decimal quantities and refuses invalid values without replacing its cart', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => '1.6', 'price' => '20000', 'price_quantity' => '1.5']);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->call('setQuantity', $variant->id, '1,6')->assertHasNoErrors()
        ->assertSet('cart.'.$variant->id, '1.600');
    expect($component->get('subtotal'))->toBe('21333.33');
    foreach (['0', '-1', '1.0001', 'invalid'] as $value) {
        $component->call('setQuantity', $variant->id, $value)->assertHasErrors('quantity')
            ->assertSet('cart.'.$variant->id, '1.600');
    }
    $component->call('setQuantity', $variant->id, '1.601')->assertSet('cart.'.$variant->id, '1.600');
});

test('a price basis change requires POS reconfirmation even when the price is unchanged', function (): void {
    $variant = ProductVariant::factory()->create(['price' => '20000', 'price_quantity' => '1.5', 'stock_available' => '10']);
    $customer = Customer::factory()->create();
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->callAction('customer', ['customer_id' => $customer->id, 'recipient_name' => $customer->name, 'recipient_phone' => $customer->phone])
        ->mountAction('checkout');
    $variant->update(['price_quantity' => '2']);
    $component->setActionData(['shipping_method' => 'pickup', 'payment_method' => 'cash', 'payment_received' => true, 'discount_amount' => '0', 'shipping_cost' => '0'])
        ->callMountedAction()->assertHasActionErrors(['prices'])
        ->assertSet('priceQuantities.'.$variant->id, '2.000');
    expect(Order::count())->toBe(0);
});

test('fractional stock survives reservation release shipping and completion', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => '2.125']);
    $warehouse = Warehouse::query()->where('code', 'WH-SCCR')->firstOrFail();
    StockMutation::reserveForOrder($variant, '1.6', 'line-one', $this->actor->id, $warehouse);
    expect($variant->fresh()->stock_available)->toBe('0.525');
    StockMutation::releaseFromOrder($variant->fresh(), '1.6', 'line-one', $this->actor->id, $warehouse);
    expect($variant->fresh()->stock_available)->toBe('2.125');
    StockMutation::reserveForOrder($variant->fresh(), '0.125', 'line-two', $this->actor->id, $warehouse);
    StockMutation::shipForOrder($variant->fresh(), '0.125', 'line-two', $this->actor->id, $warehouse);
    StockMutation::fulfillForOrder($variant->fresh(), '0.125', 'line-two', $this->actor->id, $warehouse);
    expect($variant->fresh()->stock_out)->toBe('0.125');
    expect($variant->fresh()->stock_reserved)->toBe('0.000');
    expect($variant->fresh()->stock_in_transit)->toBe('0.000');
});

test('fractional transfers adjustments and stocktakes preserve three decimal places', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '2.125']);
    $stock = WarehouseStock::factory()->create(['warehouse_id' => $from->id, 'product_variant_id' => $variant->id, 'stock_available' => '2.125']);
    StockMovement::transfer($from, $to, $variant, '0.125');
    expect($stock->fresh()->stock_available)->toBe('2.000');
    StockMutation::record($variant, StockMutationSource::ManualAdjustment, '0.001', warehouse: $from);
    expect($stock->fresh()->stock_available)->toBe('2.001');
    $opname = StockOpname::record($from, $variant, '1.999');
    expect($opname->difference)->toBe('-0.002');
    expect($stock->fresh()->stock_available)->toBe('1.999');
});

test('pricing rounds half up and respects promotions and legacy unit prices', function (): void {
    expect(VariantPricing::subtotal('0.01', '1', '2'))->toBe('0.01');
    $variant = ProductVariant::factory()->create(['price' => '20000', 'price_quantity' => '1.5']);
    ProductVariantPrice::factory()->for($variant)->create(['price' => '18000', 'effective_from' => today()]);
    expect(VariantPricing::subtotal($variant->effective_price, '1.6', $variant->price_quantity))->toBe('19200.00');
    $legacy = ProductVariant::factory()->create(['price' => '20000']);
    expect(VariantPricing::subtotal($legacy->effective_price, '2', $legacy->price_quantity))->toBe('40000.00');
});

test('partial decimal dispatch and receipt keep accurate warehouse balances', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '5.125']);
    $stock = WarehouseStock::factory()->create(['warehouse_id' => $from->id, 'product_variant_id' => $variant->id, 'stock_available' => '5.125']);
    $movement = StockMovement::factory()->create(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id]);
    $item = StockMovementItem::factory()->create(['stock_movement_id' => $movement->id, 'product_variant_id' => $variant->id, 'quantity' => '1.625']);
    $movement->reserveRequestedStock();
    $movement->ship([['item_id' => $item->id, 'quantity_shipped' => '1.5', 'notes' => 'Partial dispatch']]);
    expect($stock->fresh()->stock_available)->toBe('3.625');
    expect($stock->fresh()->stock_in_transit)->toBe('1.500');
    $movement->refresh()->receive([['item_id' => $item->id, 'quantity_received' => '1.499', 'notes' => 'Weighing difference']]);
    expect($item->fresh()->quantity_received)->toBe('1.499');
    expect($stock->fresh()->stock_in_transit)->toBe('0.000');
    expect(WarehouseStock::where('warehouse_id', $to->id)->where('product_variant_id', $variant->id)->sole()->stock_available)->toBe('1.499');
});

test('cart merging preserves fractional quantities and uses current price basis', function (): void {
    $customer = Customer::factory()->registered()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '10', 'price' => '20000', 'price_quantity' => '1.5']);
    $cart = Cart::forCustomer($customer);
    $item = $cart->addItem($variant, '0.125');
    expect($item->fresh()->quantity)->toBe('0.125');
    expect($item->fresh()->subtotal)->toBe('1666.67');
    $cart->addItem($variant, '0.125');
    expect($item->fresh()->quantity)->toBe('0.250');
    expect($item->fresh()->subtotal)->toBe('3333.33');
});

test('buy again retains a subunit order quantity when remaining stock is less than one', function (): void {
    $customer = Customer::factory()->registered()->create();
    $variant = ProductVariant::factory()->create(['stock_available' => '0.5', 'price' => '20000', 'price_quantity' => '1.5']);
    $order = Order::factory()->for($customer)->create();
    OrderItem::factory()->for($order)->create(['product_id' => $variant->product_id, 'product_variant_id' => $variant->id, 'quantity' => '0.125']);
    $this->actingAs($customer, 'customer');
    Livewire::test(OrderHistory::class)->call('buyAgain', $order->id)->assertRedirect();
    $item = Cart::forCustomer($customer)->items()->sole();
    expect($item->quantity)->toBe('0.125');
    expect($item->subtotal)->toBe('1666.67');
});

test('POS allows the last fraction of stock to be selected and entered', function (): void {
    $variant = ProductVariant::factory()->create(['stock_available' => '0.125', 'price' => '20000', 'price_quantity' => '1.5']);
    $component = Livewire::test(Pos::class)->call('addVariant', $variant->id)
        ->call('setQuantity', $variant->id, '0.125')->assertHasNoErrors()
        ->assertSet('cart.'.$variant->id, '0.125');
    expect($component->get('subtotal'))->toBe('1666.67');
});
