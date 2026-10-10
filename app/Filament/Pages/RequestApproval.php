<?php

namespace App\Filament\Pages;

use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use App\Filament\Tables\ApplicationTable;
use App\Models\User;
use App\Services\ApprovalRequestInbox as ApprovalRequestInboxService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestApproval extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = -1;

    protected static ?string $title = 'Request Approval';

    protected static ?string $slug = 'request-approval';

    protected string $view = 'filament.pages.request-approval';

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User && app(ApprovalRequestInboxService::class)->canAccess($user);
    }

    public function table(Table $table): Table
    {
        return ApplicationTable::configureColumnManager($table
            ->records(fn (
                int|string $page,
                int|string $recordsPerPage,
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
            ): LengthAwarePaginator => app(ApprovalRequestInboxService::class)->paginate(
                user: $this->authenticatedUser(),
                page: (int) $page,
                perPage: $recordsPerPage,
                search: $search,
                sortColumn: $sortColumn,
                sortDirection: $sortDirection,
                pageName: $this->getTablePaginationPageName(),
            ))
            ->columns([
                TextColumn::make('type')->label('Type')->badge()->color('gray')->sortable(),
                TextColumn::make('number')->label('Number')->searchable()->placeholder('-')->sortable(),
                TextColumn::make('date')->label('Date')->date('d M Y')->placeholder('-')->sortable(),
                TextColumn::make('requested_by')->label('Requested By')->searchable()->placeholder('-')->sortable(),
                TextColumn::make('status')->label('Status')->badge()->color(fn (array $record): string => $record['status_color'])->sortable(),
                TextColumn::make('progress')->label('Progress')->sortable(),
                TextColumn::make('requested_at')->label('Requested At')->dateTime('d M Y H:i')->placeholder('-')->sortable(),
                TextColumn::make('updated_at')->label('Update At')->dateTime('d M Y H:i')->placeholder('-')->sortable(),
                TextColumn::make('notes')->label('Notes')->limit(50)->wrap()->placeholder('-')->sortable(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (array $record): string => $record['review_url']),
            ])
            ->toolbarActions([
                $this->exportActionGroup(),
                BulkActionGroup::make($this->selectedExportActions())
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button()
                    ->visible(fn (): bool => app(ApprovalRequestInboxService::class)->canExportAnyModule($this->authenticatedUser())),
            ]));
    }

    private function exportActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            $this->pageExportAction('Excel', 'success', 'heroicon-o-table-cells', 'downloadExcel'),
            $this->pageExportAction('CSV', 'info', 'heroicon-o-document-text', 'downloadCsv'),
            $this->pageExportAction('PDF', 'danger', 'heroicon-o-document', 'downloadPdf'),
        ])
            ->label('Export')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => app(ApprovalRequestInboxService::class)->canExportAnyModule($this->authenticatedUser()));
    }

    private function pageExportAction(string $format, string $color, string $icon, string $serviceMethod): Action
    {
        return Action::make('export'.str($format)->lower()->studly())
            ->label("Export {$format}")
            ->icon($icon)
            ->color($color)
            ->authorize(fn (): bool => app(ApprovalRequestInboxService::class)->canExportAnyModule($this->authenticatedUser()))
            ->action(function (HasTable $livewire) use ($serviceMethod) {
                $records = $livewire->getTableRecords();
                $records = $records instanceof Paginator ? collect($records->items()) : collect($records);

                return $this->downloadExport($serviceMethod, $records, $livewire);
            });
    }

    /** @return list<BulkAction> */
    private function selectedExportActions(): array
    {
        return [
            $this->selectedExportAction('Excel', 'success', 'heroicon-o-table-cells', 'downloadExcel'),
            $this->selectedExportAction('CSV', 'info', 'heroicon-o-document-text', 'downloadCsv'),
            $this->selectedExportAction('PDF', 'danger', 'heroicon-o-document', 'downloadPdf'),
        ];
    }

    private function selectedExportAction(string $format, string $color, string $icon, string $serviceMethod): BulkAction
    {
        return BulkAction::make('exportSelected'.str($format)->lower()->studly())
            ->label("Export Selected {$format}")
            ->icon($icon)
            ->color($color)
            ->authorize(fn (): bool => app(ApprovalRequestInboxService::class)->canExportAnyModule($this->authenticatedUser()))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, HasTable $livewire) => $this->downloadExport($serviceMethod, $records, $livewire));
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function downloadExport(string $serviceMethod, Collection $records, HasTable $livewire): StreamedResponse
    {
        $records = app(ApprovalRequestInboxService::class)->exportableRows($this->authenticatedUser(), $records);
        $visibleColumns = collect($livewire->getTable()->getVisibleColumns())
            ->map(fn ($column): string => $column->getName())
            ->all();
        $definition = (new TableExportDefinition(
            title: 'Request Approval',
            columns: [
                'type' => 'Type',
                'number' => 'Number',
                'date' => 'Date',
                'requested_by' => 'Requested By',
                'status' => 'Status',
                'progress' => 'Progress',
                'requested_at' => 'Requested At',
                'updated_at' => 'Update At',
                'notes' => 'Notes',
            ],
            dateOnly: ['date'],
        ))->onlyColumns($visibleColumns);

        return app(TableExportService::class)->{$serviceMethod}($records, self::class, $definition);
    }

    private function authenticatedUser(): User
    {
        $user = auth('web')->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
