<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderResource extends Resource implements HasTableExport
{
    protected static ?string $model = Order::class;

    /*
    |--------------------------------------------------------------------------
    | LABEL UI
    |--------------------------------------------------------------------------
    */

    public static function getNavigationLabel(): string
    {
        return 'Orders';
    }

    public static function getModelLabel(): string
    {
        return 'Order';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Orders';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Order::query()
            ->whereNotIn('status', [OrderStatus::Done->value, OrderStatus::Cancelled->value])
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | NAVIGATION
    |--------------------------------------------------------------------------
    */

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedShoppingBag;

    protected static string|\UnitEnum|null $navigationGroup =
        'Operations';

    /*
    |--------------------------------------------------------------------------
    | RECORD
    |--------------------------------------------------------------------------
    */

    protected static ?string $recordTitleAttribute =
        'order_number';

    /*
    |--------------------------------------------------------------------------
    | PERMISSIONS
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | FORM
    |--------------------------------------------------------------------------
    */

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    /*
    |--------------------------------------------------------------------------
    | INFOLIST
    |--------------------------------------------------------------------------
    */

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    /*
    |--------------------------------------------------------------------------
    | TABLE
    |--------------------------------------------------------------------------
    */

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    /*
    |--------------------------------------------------------------------------
    | PAGES
    |--------------------------------------------------------------------------
    */

    public static function getPages(): array
    {
        return [
            'index' => ManageOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | EXPORT
    |--------------------------------------------------------------------------
    */

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Orders',

            columns: [
                'order_number' => 'Order Number',
                'recipient_name' => 'Customer',
                'recipient_phone' => 'Recipient Phone Number',
                'shipping_address' => 'Delivery Address',
                'payment_method' => 'Payment Method',
                'status' => 'Order Status',
                'stock_reservation_status' => 'Stock Reservation',
                'stock_reservation_error' => 'Reservation Error',
                'cancelled_at' => 'Cancelled At',
                'shipping_method' => 'Shipping Method',
                'payment.method' => 'Payment Method',
                'payment.status' => 'Payment Status',
                'subtotal' => 'Subtotal',
                'discount_amount' => 'Discount',
                'shipping_cost' => 'Shipping Cost',
                'total' => 'Total',
                'tracking_number' => 'Tracking Number',
                'createdBy.name' => 'Created By',
                'created_at' => 'Order Date',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
            ],

            relationships: [
                'payment',
                'createdBy',
                'updatedBy',
            ],

            monetary: [
                'subtotal',
                'discount_amount',
                'shipping_cost',
                'total',
            ],
        );
    }
}
