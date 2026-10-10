<?php

namespace App\Filament\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Exports\PaymentExportService;
use App\Filament\Tables\ApplicationTable;
use App\Models\Payment;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Sales Reports';

    protected static ?string $slug = 'sales-reports';

    protected string $view = 'filament.pages.sales-reports';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array{start: string, end: string}|null */
    #[Locked]
    public ?array $period = null;

    public static function canAccess(): bool
    {
        return auth('web')->user()?->can('ViewAny:Payment')
            && auth('web')->user()?->can('Export:Payment')
            && auth('web')->user()?->can('ViewAny:Order');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(2)->components([
            DatePicker::make('start')->label('Start Date')->displayFormat('d/m/Y')->placeholder('dd/mm/yyyy')->extraFieldWrapperAttributes(['class' => 'sales-reports-date-field'])->required()->rules(['date_format:Y-m-d']),
            DatePicker::make('end')->label('End Date')->displayFormat('d/m/Y')->placeholder('dd/mm/yyyy')->extraFieldWrapperAttributes(['class' => 'sales-reports-date-field'])->required()->rules(['date_format:Y-m-d'])->afterOrEqual('start'),
        ]);
    }

    public function loadPayments(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $this->period = ['start' => $data['start'], 'end' => $data['end']];
        $this->resetTable();
    }

    /** @return Builder<Payment> */
    protected function paymentsQuery(): Builder
    {
        abort_unless(static::canAccess(), 403);
        $query = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->with(['order.items' => fn (HasMany $query): HasMany => $query->orderBy('id'), 'createdBy']);

        if ($this->period === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('created_at', '>=', CarbonImmutable::parse($this->period['start'], 'Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone')))
            ->where('created_at', '<', CarbonImmutable::parse($this->period['end'], 'Asia/Jakarta')->addDay()->startOfDay()->setTimezone(config('app.timezone')));
    }

    public function table(Table $table): Table
    {
        return ApplicationTable::configureColumnManager($table
            ->query(fn (): Builder => $this->paymentsQuery())
            ->content(view('filament.tables.sales-reports'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->emptyStateHeading('No sales to display')
            ->emptyStateDescription(fn (): string => $this->period === null
                ? 'Choose a date range and click Preview.'
                : 'There are no paid transactions in the selected date range.')
            ->headerActions([
                Action::make('exportExcel')->label('Export Excel')->icon('heroicon-o-arrow-down-tray')
                    ->disabled(fn (): bool => $this->period === null)
                    ->action(fn (): StreamedResponse => $this->exportExcel()),
            ]));
    }

    public function exportExcel(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->period === null, 422);

        return app(PaymentExportService::class)->download($this->paymentsQuery()->orderByDesc('created_at')->orderByDesc('id'), $this->period['start'], $this->period['end']);
    }
}
