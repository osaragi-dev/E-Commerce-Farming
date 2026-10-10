<?php

namespace App\Filament\Pages;

use App\Enums\CustomerType;
use App\Enums\PaymentStatus;
use App\Filament\Support\Rupiah;
use App\Filament\Tables\ApplicationTable;
use App\Filament\Tables\InvoiceOrderSelectionTable;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceDocumentService;
use App\Services\InvoiceGenerationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class GenerateInvoice extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.generate-invoice';

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    public ?string $previewToken = null;

    #[Locked]
    public ?string $generatedInvoiceId = null;

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Invoice::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'generation_method' => 'by_date',
            'start_date' => now('Asia/Jakarta')->startOfMonth()->toDateString(),
            'end_date' => now('Asia/Jakarta')->toDateString(),
            'invoice_date' => now('Asia/Jakarta')->toDateString(),
            'due_date' => now('Asia/Jakarta')->addDays(3)->toDateString(),
            'selected_order_ids' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(2)->components([
            Hidden::make('generation_method')->required()->in(['by_date', 'by_selected_order']),
            Select::make('customer_id')->label('Customer')->searchable()->required()->live()
                ->options(fn (): array => Customer::query()->saved()->where('is_active', true)
                    ->where('customer_type', CustomerType::Invoice)->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(fn (Set $set) => $set('selected_order_ids', []))
                ->helperText('Only active Invoice customers are available.')->columnSpanFull(),
            DatePicker::make('start_date')->label('Start Date')->required()
                ->visible(fn (Get $get): bool => $get('generation_method') === 'by_date'),
            DatePicker::make('end_date')->label('End Date')->required()->afterOrEqual('start_date')
                ->visible(fn (Get $get): bool => $get('generation_method') === 'by_date'),
            DatePicker::make('invoice_date')->label('Invoice Date')->required(),
            DatePicker::make('due_date')->label('Due Date')->required()->afterOrEqual('invoice_date'),
            ModalTableSelect::make('selected_order_ids')->label('Orders')->model(Customer::class)
                ->relationship('orders', 'order_number', modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                    ->whereIn('orders.id', app(InvoiceGenerationService::class)->availableOrders($get('customer_id'))->select('orders.id')))
                ->multiple()->dehydrated()->live()
                ->tableConfiguration(InvoiceOrderSelectionTable::class)
                ->tableArguments(fn (Get $get): array => ['customer_id' => $get('customer_id')])
                ->getOptionLabelFromRecordUsing(fn (Order $record): string => $record->order_number)
                ->disabled(fn (Get $get): bool => blank($get('customer_id')))
                ->helperText('Select a customer, then choose unpaid orders that have not been invoiced.')
                ->selectAction(fn (Action $action): Action => $action->label('Select Orders')
                    ->modalHeading('Select Invoice Orders')->modalSubmitActionLabel('Use Selected Orders')
                    ->slideOver()->modalWidth('7xl'))
                ->required()->minItems(1)->maxItems(500)->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('generation_method') === 'by_selected_order'),
        ]);
    }

    public function updatedData(): void
    {
        $this->invalidatePreview();
    }

    public function invalidatePreview(): void
    {
        if ($this->previewToken !== null) {
            Cache::forget('invoice-preview:'.$this->previewToken);
        }
        $this->previewToken = null;
        $this->generatedInvoiceId = null;
    }

    public function preview(): void
    {
        abort_unless(static::canAccess(), 403);
        Gate::authorize('create', Invoice::class);
        $this->invalidatePreview();
        $data = $this->form->getState();
        $payload = app(InvoiceGenerationService::class)->preview($data);
        app(InvoiceDocumentService::class)->pdf($payload);
        $this->previewToken = (string) Str::uuid7();
        Cache::put('invoice-preview:'.$this->previewToken, [
            'user_id' => auth('web')->id(),
            'payload' => $payload,
            'fingerprint' => app(InvoiceGenerationService::class)->fingerprint($payload),
        ], now()->addMinutes(10));
    }

    public function generateInvoice(): void
    {
        abort_unless(static::canAccess(), 403);
        Gate::authorize('create', Invoice::class);
        if ($this->generatedInvoiceId !== null && Invoice::query()
            ->whereKey($this->generatedInvoiceId)
            ->where('generation_token', $this->previewToken)
            ->where('created_by', auth('web')->id())->exists()) {
            return;
        }
        $preview = $this->previewToken ? Cache::get('invoice-preview:'.$this->previewToken) : null;
        if (! $preview || $preview['user_id'] !== auth('web')->id()) {
            throw ValidationException::withMessages(['data.customer_id' => 'Preview the invoice before generating it. Previews expire after 10 minutes.']);
        }
        $invoice = app(InvoiceGenerationService::class)->generate($this->form->getState(), $preview['fingerprint'], $this->previewToken);
        $this->generatedInvoiceId = $invoice->id;
        $this->resetTable();
        Notification::make()->title('Invoice generated')->body($invoice->invoice_number)->success()->send();
    }

    public function table(Table $table): Table
    {
        return ApplicationTable::configureColumnManager($table
            ->query(Invoice::query()->with(['payments', 'createdBy']))
            ->columns([
                TextColumn::make('invoice_number')->label('Number')->searchable()->sortable(),
                TextColumn::make('invoice_date')->date('d M Y')->sortable(),
                TextColumn::make('due_date')->date('d M Y')->sortable(),
                TextColumn::make('customer_name')->label('Customer')->searchable(),
                TextColumn::make('total')->formatStateUsing(fn (string $state): string => Rupiah::formatWithPrefix($state))->alignEnd()->sortable(),
                TextColumn::make('payment_status')->label('Status')->state(fn (Invoice $record): string => $record->paymentStatusLabel())->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Paid' => 'success', 'Needs Review' => 'danger', default => 'warning'
                    }),
                TextColumn::make('createdBy.name')->label('Created By')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('payment_status')
                    ->label('Payment Status')
                    ->options([
                        PaymentStatus::Paid->value => PaymentStatus::Paid->getLabel(),
                        PaymentStatus::Unpaid->value => PaymentStatus::Unpaid->getLabel(),
                    ])
                    ->query(function (Builder $query, mixed $state): Builder {
                        $value = is_array($state) ? ($state['value'] ?? null) : $state;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query
                            ->whereHas('payments', fn (Builder $paymentQuery): Builder => $paymentQuery->where('status', $value))
                            ->whereDoesntHave('payments', fn (Builder $paymentQuery): Builder => $paymentQuery->where('status', '!=', $value));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('previewInvoice')->label('Preview')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->visible(fn (Invoice $record): bool => Gate::allows('view', $record))
                    ->url(fn (Invoice $record): string => route('admin.invoices.show', $record))->openUrlInNewTab(),
                Action::make('markPaid')->label('Mark Paid')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (Invoice $record): bool => Gate::allows('view', $record) && Gate::allows('Verify:Payment') && $record->paymentStatusLabel() !== 'Paid')
                    ->requiresConfirmation()
                    ->modalDescription('Mark every order linked to this invoice as paid.')
                    ->action(function (Invoice $record): void {
                        app(InvoiceGenerationService::class)->setPaid($record, true);
                        Notification::make()->title('Invoice marked as paid')->success()->send();
                    }),
                Action::make('markUnpaid')->label('Mark Unpaid')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                    ->visible(fn (Invoice $record): bool => Gate::allows('view', $record) && Gate::allows('Verify:Payment') && $record->paymentStatusLabel() === 'Paid')
                    ->requiresConfirmation()
                    ->modalDescription('Reopen every payment linked to this invoice as unpaid. Order fulfillment will not change.')
                    ->action(function (Invoice $record): void {
                        app(InvoiceGenerationService::class)->setPaid($record, false);
                        Notification::make()->title('Invoice marked as unpaid')->success()->send();
                    }),
                Action::make('deleteInvoice')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                    ->visible(fn (Invoice $record): bool => Gate::allows('delete', $record) && $record->paymentStatusLabel() === 'Unpaid')
                    ->modalDescription('Delete this unpaid invoice and make its orders available for invoicing again. Its invoice number will not be reused.')
                    ->action(function (Invoice $record): void {
                        app(InvoiceGenerationService::class)->delete($record);
                        $this->invalidatePreview();
                        Notification::make()->title('Invoice deleted')->success()->send();
                    }),
                ActionGroup::make([
                    Action::make('downloadPdf')->label('PDF')->icon(Heroicon::OutlinedDocument)->color('danger')
                        ->url(fn (Invoice $record): string => route('admin.invoices.download', [$record, 'pdf'])),
                    Action::make('downloadWord')->label('Word')->icon(Heroicon::OutlinedDocumentText)->color('info')
                        ->url(fn (Invoice $record): string => route('admin.invoices.download', [$record, 'docx'])),
                ])->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (Invoice $record): bool => Gate::allows('export', $record)),
            ]));
    }
}
