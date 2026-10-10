<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\ShippingLocation;
use App\Enums\ShippingMethod;
use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Filament\Support\Rupiah;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\ShippingSetting;
use App\Support\Quantity;
use App\Support\VariantPricing;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer & Shipping')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->options(fn (): array => Customer::query()
                                ->saved()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $customer = $state ? Customer::find($state) : null;

                                $set('recipient_name', $customer?->name);
                                $set('recipient_phone', $customer?->phone);

                                if ($get('payment_method') === PaymentMethod::Invoice->value && $customer?->customer_type !== CustomerType::Invoice) {
                                    $set('payment_method', null);
                                }
                            }),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('recipient_name')
                                    ->label('Recipient Name')
                                    ->maxLength(255),
                                TextInput::make('recipient_phone')
                                    ->label('Recipient Phone')
                                    ->required()
                                    ->maxLength(30),
                            ]),

                        Radio::make('shipping_method')
                            ->label('Shipping Method')
                            ->options(ShippingMethod::class)
                            ->inline()
                            ->default(ShippingMethod::Delivery->value)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $shippingMethod = self::shippingMethodFromState($state);

                                $set('shipping_address_other', null);
                                $set(
                                    'shipping_address',
                                    $shippingMethod === ShippingMethod::Pickup
                                        ? ShippingLocation::AgungFarmAndGarden->value
                                        : null,
                                );
                            }),

                        Select::make('shipping_address')
                            ->label('Shipping Address')
                            ->options(fn (Get $get): array => self::shippingAddressOptions(
                                self::shippingMethodFromState($get('shipping_method')),
                            ))
                            ->selectablePlaceholder(fn (Get $get): bool => self::shippingMethodFromState($get('shipping_method')) !== ShippingMethod::Pickup)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                if ($state !== 'Others') {
                                    $set('shipping_address_other', null);
                                }
                            })
                            ->helperText(function (Get $get): ?string {
                                $shippingMethod = self::shippingMethodFromState($get('shipping_method'));

                                return $shippingMethod === ShippingMethod::Pickup
                                    && $get('shipping_address') === ShippingLocation::AgungFarmAndGarden->value
                                    ? 'Pickup location: Agung Farm & Garden.'
                                    : null;
                            })
                            ->columnSpanFull(),

                        Textarea::make('shipping_address_other')
                            ->label('Other Shipping Address')
                            ->required(fn (Get $get): bool => $get('shipping_address') === 'Others')
                            ->visible(fn (Get $get): bool => $get('shipping_address') === 'Others')
                            ->maxLength(1000)
                            ->rows(3)
                            ->columnSpanFull(),

                        Placeholder::make('fulfillment_warehouse_info')
                            ->label('Fulfillment Warehouse')
                            ->content('Orders reserve stock only from the active POS warehouse.'),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),

                Section::make('Products')
                    ->schema([
                        Repeater::make('items')
                            ->label('')
                            ->schema([
                                Grid::make([
                                    'default' => 1,
                                    'md' => 12,
                                ])
                                    ->schema([
                                        Select::make('product_variant_id')
                                            ->label('Product')
                                            ->options(fn (): array => self::productVariantOptions())
                                            ->searchable()
                                            ->required()
                                            ->live()
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 6,
                                            ]),
                                        QuantityInput::make('quantity')
                                            ->label('Qty')

                                            ->minValue(0.001)
                                            ->default(1)
                                            ->required()
                                            ->live()
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 2,
                                            ]),
                                        Placeholder::make('line_subtotal')
                                            ->label('Subtotal')
                                            ->content(function (Get $get): string {
                                                $variant = $get('product_variant_id')
                                                    ? ProductVariant::query()->with('pricePeriods')->find($get('product_variant_id'))
                                                    : null;

                                                if (! $variant) {
                                                    return '—';
                                                }

                                                $qty = Quantity::normalize($get('quantity') ?? '0');
                                                if (! is_numeric($qty) || $qty <= 0) {
                                                    return '�';
                                                }
                                                $subtotal = VariantPricing::subtotal($variant->effective_price, $qty, $variant->price_quantity);

                                                return Rupiah::formatWithPrefix($subtotal) ?? '—';
                                            })
                                            ->columnSpan([
                                                'default' => 1,
                                                'md' => 4,
                                            ]),
                                    ]),
                            ])
                            ->addActionLabel('Add Product')
                            ->minItems(1)
                            ->required()
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Order Summary')
                    ->schema([
                        Placeholder::make('order_summary')
                            ->label('')
                            ->content(function (Get $get): HtmlString {
                                $items = $get('items') ?? [];
                                $subtotal = '0';
                                $lines = '';

                                foreach ($items as $item) {
                                    $variantId = $item['product_variant_id'] ?? null;
                                    $quantity = Quantity::normalize($item['quantity'] ?? '0');

                                    if (! $variantId || ! is_numeric($quantity) || $quantity <= 0) {
                                        continue;
                                    }

                                    $variant = ProductVariant::query()->with(['product', 'pricePeriods'])->find($variantId);

                                    if (! $variant) {
                                        continue;
                                    }

                                    // Same formula CreateOrder.php uses per line — the summary
                                    // must never invent its own calculation.
                                    $lineSubtotal = VariantPricing::subtotal($variant->effective_price, $quantity, $variant->price_quantity);
                                    $subtotal = bcadd($subtotal, $lineSubtotal, 2);

                                    $quantity = Quantity::format($quantity);
                                    $label = e("{$variant->product?->name} ({$variant->name}) × {$quantity}");
                                    $amount = e(Rupiah::formatWithPrefix($lineSubtotal) ?? '—');

                                    $lines .= "<div class=\"flex items-center justify-between gap-4 text-sm py-1\"><span>{$label}</span><span>{$amount}</span></div>";
                                }

                                if ($lines === '') {
                                    $lines = '<div class="text-sm" style="color: var(--gray-500, #6b7280)">No products selected yet.</div>';
                                }

                                // Same formula CreateOrder.php uses for the order total.
                                $shippingCost = Rupiah::normalize($get('shipping_cost')) ?? '0';
                                $total = bcadd($subtotal, $shippingCost, 2);

                                $subtotalFormatted = e(Rupiah::formatWithPrefix($subtotal) ?? '—');
                                $shippingFormatted = e(Rupiah::formatWithPrefix($shippingCost) ?? '—');
                                $totalFormatted = e(Rupiah::formatWithPrefix($total) ?? '—');

                                return new HtmlString(<<<HTML
                                    <div class="space-y-1">
                                        {$lines}
                                        <div class="border-t pt-2 mt-2 space-y-1">
                                            <div class="flex items-center justify-between gap-4 text-sm">
                                                <span>Subtotal</span>
                                                <span>{$subtotalFormatted}</span>
                                            </div>
                                            <div class="flex items-center justify-between gap-4 text-sm">
                                                <span>Shipping Cost (estimate)</span>
                                                <span>{$shippingFormatted}</span>
                                            </div>
                                            <div class="flex items-center justify-between gap-4 text-base font-semibold border-t pt-2 mt-2">
                                                <span>Total</span>
                                                <span>{$totalFormatted}</span>
                                            </div>
                                        </div>
                                    </div>
                                    HTML);
                            })
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Payment')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('payment_method')
                                    ->label('Payment Method')
                                    ->options(fn (Get $get) => collect(PaymentMethod::cases())
                                        ->reject(fn (PaymentMethod $method): bool => $method === PaymentMethod::Invoice
                                            && ! Customer::query()->saved()->where('is_active', true)->where('customer_type', CustomerType::Invoice)->whereKey($get('customer_id'))->exists())
                                        ->mapWithKeys(fn (PaymentMethod $m): array => [$m->value => $m->getLabel()]))
                                    ->required()
                                    ->live(),

                                RupiahInput::make('shipping_cost')
                                    ->label('Shipping Cost')
                                    ->default(fn (): float => (float) ShippingSetting::current()->flat_shipping_cost)
                                    ->live()
                                    ->required(),
                            ]),

                        View::make('filament.forms.components.company-bank-transfer')
                            ->visible(fn (Get $get): bool => $get('payment_method') === PaymentMethod::BankTransfer->value)
                            ->columnSpanFull(),
                        View::make('filament.forms.components.company-qris')
                            ->visible(fn (Get $get): bool => $get('payment_method') === PaymentMethod::Qris->value)
                            ->columnSpanFull(),

                        FileUpload::make('payment_proof')
                            ->label(fn (Get $get): string => 'Payment Proof ('.(PaymentMethod::tryFrom((string) $get('payment_method'))?->getLabel() ?? '').')')
                            ->image()
                            ->disk('public')
                            ->directory('payment-proofs')
                            ->visible(fn (Get $get): bool => (PaymentMethod::tryFrom((string) $get('payment_method'))?->requiresProof() ?? false))
                            ->columnSpanFull(),

                        Textarea::make('notes')
                            ->label('Notes')
                            ->helperText('Required when payment proof has not been uploaded.')
                            ->rules(['regex:/\S/u'])
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function shippingMethodFromState(mixed $state): ?ShippingMethod
    {
        if ($state instanceof ShippingMethod) {
            return $state;
        }

        return filled($state) ? ShippingMethod::tryFrom((string) $state) : null;
    }

    /** @return array<string, string> */
    private static function shippingAddressOptions(?ShippingMethod $shippingMethod): array
    {
        if ($shippingMethod === ShippingMethod::Pickup) {
            return [ShippingLocation::AgungFarmAndGarden->value => ShippingLocation::AgungFarmAndGarden->getLabel()];
        }

        return ShippingLocation::options() + ['Others' => 'Others'];
    }

    /** @return array<string, string> */
    private static function productVariantOptions(): array
    {
        return ProductVariant::query()
            ->inventoryVisible()
            ->with(['product', 'warehouseStocks.warehouse'])
            ->get()
            ->filter(fn (ProductVariant $variant): bool => $variant->availableForSale() > 0)
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                $variant->id => "{$variant->product?->name} - {$variant->name} (Stock: {$variant->availableForSale()})",
            ])
            ->toArray();
    }
}
