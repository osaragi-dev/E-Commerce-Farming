<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Rupiah;
use App\Models\Payment;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Default Schema::columns() adalah 2 — tanpa ini, Section top-level otomatis
            // disusun berpasangan 2 kolom (Payment Information|Payment Proof, dst),
            // makanya sebelumnya terlihat bersebelahan. Dipaksa 1 kolom supaya semua
            // Section turun ke bawah berurutan, sama seperti perbaikan di OrderInfolist.
            ->columns(1)
            ->components([
                Section::make('Void Payment Approval')
                    ->visible(fn (Payment $record): bool => $record->voidRequests()->exists())
                    ->schema([
                        TextEntry::make('void_hold')->label('Order Processing')
                            ->state(fn (Payment $record): string => $record->hasActiveVoidRequest() ? 'Order processing is on hold while a void request is active.' : 'No active void request.')
                            ->columnSpanFull(),
                        RepeatableEntry::make('voidRequests')->label('Requests')
                            ->schema([
                                TextEntry::make('cancel_order')->label('Purpose')
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Cancel order and void payment' : 'Void payment only'),
                                TextEntry::make('approval_status')->label('Status')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                                TextEntry::make('approval_level')->label('Approval Level'),
                                TextEntry::make('requester.name')->label('Requested By'),
                                TextEntry::make('created_at')->label('Requested At')->dateTime(),
                                TextEntry::make('reason')->label('Reason')->columnSpanFull(),
                                RepeatableEntry::make('logs')->label('Decision History')
                                    ->schema([
                                        TextEntry::make('created_at')->label('Time')->dateTime(),
                                        TextEntry::make('level')->label('Level'),
                                        TextEntry::make('action')->label('Action'),
                                        TextEntry::make('actedBy.name')->label('Actor'),
                                        TextEntry::make('notes')->label('Notes')->columnSpanFull(),
                                    ])
                                    ->columns(['default' => 1, 'md' => 2])
                                    ->columnSpanFull(),
                            ])
                            ->columns(['default' => 1, 'md' => 2])
                            ->columnSpanFull(),
                    ]),
                Section::make('Payment Information')
                    ->schema([
                        TextEntry::make('payment_number')->label('Payment Number')->placeholder('—'),
                        TextEntry::make('order.order_number')->label('Order Number'),
                        TextEntry::make('order.recipient_name')->label('Recipient Name'),
                        TextEntry::make('order.recipient_phone')->label('Recipient Phone Number')->placeholder('—'),
                        TextEntry::make('method')->label('Payment Method'),
                        TextEntry::make('amount')
                            ->label('Payment Amount')
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                        TextEntry::make('status')->label('Payment Status')->badge(),
                        TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime(),
                    ])
                    ->columns(3),

                Section::make('Payment Proof')
                    ->schema([
                        TextEntry::make('bank_transfer_account')
                            ->label('Bank Transfer Account')
                            ->state(fn (): string => config('invoice.bank_name').' | '.config('invoice.bank_account').' | '.config('invoice.bank_holder'))
                            ->visible(fn ($record): bool => $record?->method === PaymentMethod::BankTransfer)
                            ->columnSpanFull(),
                        ImageEntry::make('company_qris')
                            ->label('Company QRIS Code - Scan to Pay')
                            ->state(fn () => asset('images/qris/company-qris.png'))
                            ->size(320)
                            ->visible(fn ($record): bool => $record !== null && $record->method === PaymentMethod::Qris),
                        TextEntry::make('qris_info')
                            ->label('')
                            ->state(fn () => 'NMID: ID1025456363908 | QR AGUNG FARM AND GARDEN RESORT')
                            ->visible(fn ($record): bool => $record !== null && $record->method === PaymentMethod::Qris)
                            ->columnSpanFull(),
                        ImageEntry::make('proof_image')
                            ->label('Customer Payment Proof')
                            ->state(fn ($record) => $record?->currentProof()?->file_path)
                            ->disk('public')
                            ->size(320)
                            ->columnSpanFull()
                            ->url(fn ($record) => $record?->currentProof()?->file_path
                                ? Storage::disk('public')->url($record->currentProof()->file_path)
                                : null)
                            ->openUrlInNewTab()
                            ->visible(fn ($record): bool => $record !== null
                                && $record->method->requiresProof()
                                && $record->currentProof() !== null),
                        TextEntry::make('proof_filename')
                            ->label('File Name')
                            ->state(fn ($record): ?string => $record?->currentProof()?->file_path
                                ? basename($record->currentProof()->file_path)
                                : null)
                            ->columnSpanFull()
                            ->visible(fn ($record): bool => $record !== null
                                && $record->method->requiresProof()
                                && $record->currentProof() !== null),
                        TextEntry::make('proof_empty')
                            ->label('')
                            ->default('No payment proof uploaded yet.')
                            ->visible(fn ($record): bool => $record !== null
                                && $record->method->requiresProof()
                                && $record->currentProof() === null),
                        TextEntry::make('proof_uploaded')
                            ->label('Uploaded At')
                            ->state(fn ($record) => $record?->currentProof()?->uploaded_at)
                            ->dateTime()
                            ->placeholder('—')
                            ->visible(fn ($record): bool => $record !== null && $record->currentProof() !== null),
                        TextEntry::make('resubmission_deadline_at')
                            ->label('Upload Resubmission Deadline')
                            ->state(fn ($record) => $record?->currentProof()?->resubmission_deadline_at)
                            ->dateTime('d-m-Y H:i', 'Asia/Jakarta')
                            ->placeholder('—')
                            ->visible(fn ($record): bool => $record !== null
                                && $record->status === PaymentStatus::Rejected
                                && $record->currentProof()?->resubmission_deadline_at !== null),
                        TextEntry::make('proof_upload_deadline_at')
                            ->label('Initial Upload Deadline')
                            ->dateTime('d-m-Y H:i', 'Asia/Jakarta')
                            ->placeholder('—')
                            ->visible(fn ($record): bool => $record !== null
                                && $record->method->requiresProof()
                                && $record->status === PaymentStatus::Unpaid
                                && $record->proof_upload_deadline_at !== null),
                    ])
                    ->columns(2),

                Section::make('Payment History')
                    ->schema([
                        RepeatableEntry::make('proofs')
                            ->label('Payment Proof History')
                            ->schema([
                                TextEntry::make('status')->label('Status')->badge(),
                                TextEntry::make('uploaded_at')->label('Uploaded At')->dateTime(),
                                TextEntry::make('reviewedBy.name')->label('Reviewed By')->placeholder('—'),
                                TextEntry::make('reviewed_at')->label('Reviewed At')->dateTime()->placeholder('—'),
                                TextEntry::make('rejection_reason')->label('Rejection Reason')->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(4),
                        TextEntry::make('verifiedBy.name')->label('Verified By')->placeholder('—'),
                        TextEntry::make('verified_at')->label('Verified At')->dateTime()->placeholder('—'),
                        TextEntry::make('rejection_reason')
                            ->label('Rejection Reason')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Order Information')
                    ->schema([
                        TextEntry::make('order.order_number')
                            ->label('Order Number')
                            ->color('info')
                            ->extraEntryWrapperAttributes(['class' => 'payment-order-number-link'])
                            ->url(fn (Payment $record): ?string => $record->order !== null
                                && OrderResource::canView($record->order)
                                ? OrderResource::getUrl(parameters: [
                                    'tableAction' => 'view',
                                    'tableActionRecord' => $record->order,
                                ])
                                : null),
                        TextEntry::make('order.status')
                            ->label('Order Status')
                            ->state(fn (Payment $record): ?string => $record->order?->adminStatusLabel(
                                paymentStatus: $record->status,
                                collapseWaitingConfirmation: false,
                            ))
                            ->color(fn (Payment $record): string => $record->order?->adminStatusColor(
                                collapseWaitingConfirmation: false,
                            ) ?? 'gray')
                            ->badge(),
                        TextEntry::make('order.recipient_name')->label('Recipient Name'),
                        TextEntry::make('order.recipient_phone')->label('Recipient Phone Number')->placeholder('—'),
                        TextEntry::make('order.total')
                            ->label('Order Total')
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                    ])
                    ->columns(4),

                Section::make('Payment Guidance')
                    ->schema([
                        TextEntry::make('guidance')
                            ->label('')
                            ->state(fn ($record): string => match ($record->status) {
                                PaymentStatus::Unpaid => $record->order?->status === OrderStatus::Cancelled
                                    ? 'Payment is unpaid. The order has been cancelled and cannot be processed.'
                                    : ($record->method->requiresProof()
                                        ? 'Payment is unpaid. The order is waiting for a payment proof before it can be processed.'
                                        : 'Cash payment has not been received. The order can be processed through Done. Use Confirm Payment when cash is received in full.'),
                                PaymentStatus::Paid => 'Payment has been received and confirmed.',
                                PaymentStatus::Rejected => 'Payment proof has been rejected. The customer can upload a new payment proof, which returns the payment to Waiting Confirm.',
                                PaymentStatus::UnpaidCancel => 'Payment was unpaid when the order was cancelled and is no longer active.',
                                PaymentStatus::Void => 'Payment has been voided/canceled and is no longer active.',
                                default => $record->method === PaymentMethod::Cash
                                    ? 'Cash payment is waiting for confirmation. Payment will become Paid after the admin confirms that the cash has been received.'
                                    : 'Review the current payment proof before confirming or rejecting this payment.',
                            }),
                    ]),
            ]);
    }
}
