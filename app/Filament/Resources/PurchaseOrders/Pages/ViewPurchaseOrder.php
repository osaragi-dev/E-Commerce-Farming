<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\PurchaseOrderCloseApprovalStatus;
use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Forms\Components\RupiahInput;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Rupiah;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
use App\Models\User;
use App\Services\ApprovalWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'View Purchase Order';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createPurchaseInvoice')
                ->label('Create Purchase Invoice')
                ->color('primary')
                ->url(fn (PurchaseOrder $record): string => PurchaseInvoiceResource::getUrl('create', ['purchase_order_id' => $record->id]))
                ->visible(fn (PurchaseOrder $record): bool => $record->approval_status === PurchaseOrderApprovalStatus::Approved
                    && $record->closing_status !== 'closed'),
            Action::make('downloadDocx')->label('Download DOCX')->color('primary')->icon('heroicon-o-document-arrow-down')
                ->url(fn (PurchaseOrder $record): string => route('admin.purchase-orders.docx', ['record' => $record]))
                ->visible(fn (PurchaseOrder $record): bool => $record->approval_status === PurchaseOrderApprovalStatus::Approved),
            Action::make('recordGoodsReceipt')->label('Record Goods Receipt')->color('info')->icon('heroicon-o-inbox-arrow-down')
                ->url(fn (PurchaseOrder $record): string => GoodsReceiptResource::getUrl('create', ['purchase_order_id' => $record->id]))
                ->visible(fn (PurchaseOrder $record): bool => $record->approval_status === PurchaseOrderApprovalStatus::Approved
                    && $record->closing_status !== 'closed'
                    && auth('web')->user()?->can('Update:PurchaseOrder')),
            Action::make('termin')->label('Termin')->color('success')->icon('heroicon-o-credit-card')
                ->schema([
                    Hidden::make('grn_accepted_amount')->dehydrated(false),
                    TextInput::make('payment_basis')->label('Payment Basis')->disabled()->dehydrated(),
                    RupiahInput::make('accepted_amount')->label('Accepted Amount')->disabled()->dehydrated()
                        ->helperText(fn (Get $get): string => 'GRN Accepted Amount: '.(Rupiah::formatWithPrefix($get('grn_accepted_amount')) ?? 'Rp 0,00')),
                    RupiahInput::make('tax_amount')->label('Tax Amount')->disabled()->dehydrated(),
                    RupiahInput::make('discount_amount')->label('Discount Amount')->disabled()->dehydrated(),
                    RupiahInput::make('total_payable')->label('Total Payable')->disabled()->dehydrated(),
                    RupiahInput::make('remaining_before')->label('Remaining')->disabled()->dehydrated(),
                    RupiahInput::make('payment_amount')->label('Payment Amount')->required()->minValue(0.01),
                    DatePicker::make('payment_date')->label('Payment Date')->default(now())->required(),
                    Textarea::make('notes')->label('Notes')->rows(2),
                ])
                ->fillForm(fn (PurchaseOrder $record): array => $this->terminData($record))
                ->action(fn (PurchaseOrder $record, array $data): mixed => $this->recordPayment($record, $data))
                ->visible(fn (PurchaseOrder $record): bool => $record->approval_status === PurchaseOrderApprovalStatus::Approved
                    && $record->closing_status !== 'closed'
                    && auth('web')->user()?->can('Update:PurchaseOrder')),
            Action::make('closePo')->label('Close PO')->color('danger')->icon('heroicon-o-lock-closed')->requiresConfirmation()
                ->modalDescription('Request approval to close this Purchase Order. It may be closed even when items have not been received or payments remain unpaid.')
                ->action(fn (PurchaseOrder $record): mixed => $this->requestClose($record))
                ->visible(fn (PurchaseOrder $record): bool => $record->approval_status === PurchaseOrderApprovalStatus::Approved
                    && ! in_array($record->closing_status, ['requested', 'closed'], true)
                    && auth('web')->user()?->can('Update:PurchaseOrder')),
            Action::make('approveClose')->label('Approve Close')->color('success')->icon('heroicon-o-check-circle')->requiresConfirmation()
                ->action(fn (PurchaseOrder $record): mixed => $this->decideClose($record, 'approved'))
                ->visible(fn (PurchaseOrder $record): bool => $this->canApproveClose($record)),
            Action::make('rejectClose')->label('Reject Close')->color('danger')->icon('heroicon-o-x-circle')
                ->schema([Textarea::make('notes')->label('Reason')->rows(2)])
                ->action(fn (PurchaseOrder $record, array $data): mixed => $this->decideClose($record, 'rejected', $data['notes'] ?? null))
                ->visible(fn (PurchaseOrder $record): bool => $this->canApproveClose($record)),
            ...$this->approvalActions(),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->loadRelations();
    }

    #[On('refresh-page')]
    public function refreshPage(): void
    {
        $this->record = $this->getRecord()->fresh();
        $this->loadRelations();
    }

    private function loadRelations(): void
    {
        $this->record->loadMissing([
            'approvalLogs.actedBy', 'approvalSetting.levels', 'createdBy', 'updatedBy', 'deletedBy',
            'purchaseRequest', 'warehouse', 'vendor', 'items.item', 'items.goodsReceiptItems',
            'goodsReceipts.items', 'payments.createdBy', 'purchaseInvoices', 'closeRequests.approvalLogs.actedBy',
            'closeRequests.approvalSetting.levels', 'closeRequests.requestedBy',
        ]);
    }

    /** @return array<string, string> */
    private function terminData(PurchaseOrder $record): array
    {
        $accepted = $record->acceptedAmountForBasis();
        $payable = $record->payableAmount();

        return [
            'payment_basis' => $record->payment_basis === 'goods_receipt' ? 'Goods Receipt' : 'Purchase Order',
            'grn_accepted_amount' => $record->goodsReceiptAcceptedAmount(),
            'accepted_amount' => $accepted,
            'tax_amount' => (string) $record->tax_amount,
            'discount_amount' => (string) $record->discount_amount,
            'total_payable' => $payable,
            'remaining_before' => $record->remainingAmount(),
            'payment_amount' => $record->remainingAmount(),
            'payment_date' => now()->toDateString(),
        ];
    }

    private function recordPayment(PurchaseOrder $record, array $data): void
    {
        DB::transaction(function () use ($record, $data): void {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->with(['items.goodsReceiptItems', 'payments'])->findOrFail($record->id);

            if ($lockedOrder->approval_status !== PurchaseOrderApprovalStatus::Approved || $lockedOrder->closing_status === 'closed') {
                throw ValidationException::withMessages(['payment_amount' => 'Payments can only be recorded for an open, approved Purchase Order.']);
            }

            $accepted = (float) $lockedOrder->acceptedAmountForBasis();
            $payable = max(0, $accepted + (float) $lockedOrder->tax_amount - (float) $lockedOrder->discount_amount);
            $paid = (float) $lockedOrder->payments->sum('payment_amount');
            $remaining = max(0, $payable - $paid);
            $paymentAmount = (float) ($data['payment_amount'] ?? 0);

            if ($paymentAmount <= 0 || $paymentAmount > $remaining) {
                throw ValidationException::withMessages(['payment_amount' => 'Payment amount must be greater than zero and no greater than the remaining payable amount.']);
            }

            $lockedOrder->payments()->create([
                'payment_basis' => $lockedOrder->payment_basis,
                'accepted_amount' => $accepted,
                'tax_amount' => $lockedOrder->tax_amount,
                'discount_amount' => $lockedOrder->discount_amount,
                'total_payable' => $payable,
                'payment_amount' => $paymentAmount,
                'remaining_amount' => max(0, $remaining - $paymentAmount),
                'payment_date' => $data['payment_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth('web')->id(),
            ]);
        });

        $this->refreshPage();
        Notification::make()->title('Termin saved.')->success()->send();
    }

    private function requestClose(PurchaseOrder $record): void
    {
        DB::transaction(function () use ($record): void {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->id);

            if ($lockedOrder->approval_status !== PurchaseOrderApprovalStatus::Approved) {
                throw ValidationException::withMessages(['approval' => 'Only approved Purchase Orders can be closed.']);
            }

            $closeRequest = $lockedOrder->closeRequests()->first() ?? new PurchaseOrderCloseRequest;

            if (in_array($closeRequest->approval_status, [PurchaseOrderCloseApprovalStatus::Requested, PurchaseOrderCloseApprovalStatus::Approved], true)) {
                throw ValidationException::withMessages(['approval' => 'This Purchase Order already has an active or completed close request.']);
            }

            $closeRequest->purchase_order_id = $lockedOrder->id;
            $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($closeRequest);
            $closeRequest->approval_status = $requiresApproval
                ? PurchaseOrderCloseApprovalStatus::Requested
                : PurchaseOrderCloseApprovalStatus::Approved;
            $closeRequest->approval_level = $requiresApproval ? 1 : 0;
            $closeRequest->requested_by = auth('web')->id();
            $closeRequest->updated_by = auth('web')->id();
            $closeRequest->save();

            if ($requiresApproval) {
                app(ApprovalWorkflow::class)->recordRequest($closeRequest, auth('web')->id());
            }
        });

        $this->refreshPage();
        Notification::make()->title('Purchase Order closure requested.')->success()->send();
    }

    private function canApproveClose(PurchaseOrder $record): bool
    {
        $closeRequest = $record->latest_close_request;
        $actor = auth('web')->user();

        return $closeRequest instanceof PurchaseOrderCloseRequest
            && $closeRequest->approval_status === PurchaseOrderCloseApprovalStatus::Requested
            && $actor instanceof User
            && app(ApprovalWorkflow::class)->canApprove($closeRequest, $actor);
    }

    private function decideClose(PurchaseOrder $record, string $decision, ?string $notes = null): void
    {
        $closeRequest = $record->latest_close_request;
        $actor = auth('web')->user();

        if (! $closeRequest instanceof PurchaseOrderCloseRequest || ! $actor instanceof User) {
            return;
        }

        if ($decision === 'approved') {
            app(ApprovalWorkflow::class)->approve($closeRequest, $actor);
        } else {
            app(ApprovalWorkflow::class)->reject($closeRequest, $actor, $notes);
        }

        $this->refreshPage();
        Notification::make()->title($decision === 'approved' ? 'Purchase Order closed.' : 'Close request rejected.')->success()->send();
    }

    /** @return list<Action> */
    private function approvalActions(): array
    {
        return [
            ApproveWorkflowAction::make(),
            ReviseWorkflowAction::make(),
            RejectWorkflowAction::make(),
        ];
    }
}
