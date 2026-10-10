<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Tables\Actions\RejectPaymentAction;
use App\Filament\Resources\Payments\Tables\Actions\VerifyPaymentAction;
use App\Filament\Resources\Payments\Tables\Actions\VoidPaymentAction;
use App\Filament\Tables\ApplicationTable;
use App\Filament\Tables\Columns\RupiahColumn;
use App\Models\Payment;
use App\Models\SystemFreezeState;
use App\Services\SystemFreezeGuard;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Js;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn ($query) => $query->with('voidRequests'))
            ->columns([
                TextColumn::make('payment_number')
                    ->label('Payment Number')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('order.order_number')
                    ->label('Order Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order.recipient_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('method')
                    ->label('Payment Method')
                    ->searchable(),
                RupiahColumn::make('amount')
                    ->label('Amount')
                    ->sortable(),
                ViewColumn::make('status')
                    ->label('Payment Status')
                    ->view('filament.tables.columns.payment-status'),
                TextColumn::make('verified_at')
                    ->label('Verified At')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Payment Status')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(
                        fn (PaymentStatus $status): array => [$status->value => $status->getLabel()]
                    )),
                SelectFilter::make('method')
                    ->label('Payment Method')
                    ->options(collect(PaymentMethod::cases())->mapWithKeys(
                        fn (PaymentMethod $method): array => [$method->value => $method->getLabel()]
                    )),
                DateRangeFilter::make('created_at')
                    ->label('Date')
                    ->displayFormat('d/m/Y')
                    ->startPlaceholder('dd/mm/yyyy')
                    ->endPlaceholder('dd/mm/yyyy')
                    ->firstDayOfWeek(1)
                    ->dualCalendar(false)
                    ->inline(true)
                    ->locale('en')
                    ->timezone('Asia/Jakarta'),
                SelectFilter::make('created_by')
                    ->relationship('createdBy', 'name')
                    ->label('Created By'),
                SelectFilter::make('updated_by')
                    ->relationship('updatedBy', 'name')
                    ->label('Updated By'),
            ])
            ->filtersFormWidth(Width::Large)
            ->recordActions([
                ViewAction::make()
                    ->label('View')
                    ->url(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record])),
                VerifyPaymentAction::make(),
                Action::make('uploadPaymentProof')
                    ->label('Upload Payment Proof')
                    ->authorize('Verify:Payment')
                    ->visible(fn (Payment $record): bool => $record->method === PaymentMethod::BankTransfer
                        && $record->canSubmitProof()
                        && in_array($record->status, [PaymentStatus::Unpaid, PaymentStatus::Rejected, PaymentStatus::Void], true)
                        && $record->order?->status !== OrderStatus::Cancelled)
                    ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
                    ->schema([
                        FileUpload::make('payment_proof')
                            ->label('Payment Proof (Bank Transfer)')->image()->maxSize(5120)
                            ->disk('public')->directory('payment-proofs')->required(),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        app(SystemFreezeGuard::class)->assertNotFrozen();
                        $record->submitProof($data['payment_proof'], auth()->user());
                    }),
                Action::make('printReceipt')
                    ->label('Print Receipt')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->authorize('view')
                    ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Paid)
                    ->alpineClickHandler(fn (Payment $record): string => "\$dispatch('farming-print-receipt', ".Js::from([
                        'receiptUrl' => route('admin.payments.receipt', $record),
                        'jobUrl' => route('admin.payments.print', $record),
                        'certificateUrl' => route('admin.payments.print-certificate', $record),
                        'rawbtUrl' => route('admin.payments.rawbt', $record),
                    ]).')'),
                RejectPaymentAction::make(),
                VoidPaymentAction::make(),
            ])
            ->toolbarActions([]);

        return ApplicationTable::configure($table, PaymentResource::class, hasLifecycle: false);
    }
}
