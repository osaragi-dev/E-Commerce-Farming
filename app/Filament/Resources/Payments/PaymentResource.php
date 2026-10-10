<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentStatus;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use App\Models\User;
use App\Services\ApprovalRequestInbox;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentResource extends Resource implements HasTableExport
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'payment_number';

    public static function getNavigationLabel(): string
    {
        return 'Payments';
    }

    public static function getModelLabel(): string
    {
        return 'Payment';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Payments';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Payment::query()
            ->whereIn('status', [PaymentStatus::Unpaid->value, PaymentStatus::WaitingConfirm->value])
            ->count();
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    // Payments are created by the system alongside an Order, never manually
    // by an Admin — verification happens through the Verify/Reject actions.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    public static function canView(Model $record): bool
    {
        return parent::canView($record)
            || ($record instanceof Payment
                && auth('web')->user() instanceof User
                && app(ApprovalRequestInbox::class)->canReviewDetail(auth('web')->user(), $record));
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Payments',
            columns: [
                'payment_number' => 'Payment Number',
                'order.order_number' => 'Order ID',
                'order.recipient_name' => 'Customer',
                'order.recipient_phone' => 'Recipient Phone Number',
                'method' => 'Payment Method',
                'amount' => 'Amount',
                'status' => 'Payment Status',
                'verifiedBy.name' => 'Verified By',
                'verified_at' => 'Verified At',
                'rejection_reason' => 'Rejection Reason',
                'createdBy.name' => 'Created By',
                'created_at' => 'Created At',
                'updatedBy.name' => 'Updated By',
                'updated_at' => 'Updated At',
            ],
            relationships: ['order', 'verifiedBy', 'createdBy', 'updatedBy'],
            monetary: ['amount'],
        );
    }
}
