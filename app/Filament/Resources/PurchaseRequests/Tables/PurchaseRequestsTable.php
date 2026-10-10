<?php

namespace App\Filament\Resources\PurchaseRequests\Tables;

use App\Enums\RecordStatus;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\PurchaseRequests\Schemas\PurchaseRequestForm;
use App\Filament\Tables\ApplicationTable;
use App\Models\PurchaseRequest;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseRequestsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'warehouse', 'requestedBy', 'createdBy', 'updatedBy', 'deletedBy', 'approvalSetting.levels',
            ]))
            ->columns([
                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->state(fn (PurchaseRequest $record): string => $record->approvalProgressLabel())
                    ->badge()
                    ->color(fn (PurchaseRequest $record): string => $record->approval_status?->getColor() ?? 'gray'),
                TextColumn::make('pr_number')->label('PR Number')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('pr_date')->label('PR Date')->date()->sortable(),
                TextColumn::make('warehouse.name')->label('Location')->searchable()->sortable(),
                TextColumn::make('requestedBy.name')->label('Requested By')->searchable()->sortable(),
                TextColumn::make('required_date')->label('Required Date')->date()->sortable(),
                TextColumn::make('record_status')->label('Status')->badge()->sortable(),
                TextColumn::make('approval_logs_count')->label('Log History')->counts('approvalLogs')->sortable(),
                TextColumn::make('notes')->limit(40)->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('record_status')->label('Status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                DateRangeFilter::make('pr_date')->label('PR Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (PurchaseRequest $record): string => PurchaseRequestResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, PurchaseRequest $record): array {
                        $selectedRows = PurchaseRequestForm::existingRows($record);

                        return [
                            ...$data,
                            'item_category' => 'critical',
                            'selected_item_rows' => $selectedRows,
                            'items' => PurchaseRequestForm::visibleRows($selectedRows, 'critical', $record->warehouse_id),
                        ];
                    })
                    ->mutateDataUsing(function (array $data): array {
                        $data['items'] = PurchaseRequestForm::selectedItemsFromFormState($data);
                        unset($data['selected_item_rows']);

                        return $data;
                    })
                    ->after(function (PurchaseRequest $record, Action $action): void {
                        $record->items()->delete();
                        $record->items()->createMany($action->getData()['items']);
                    })
                    ->visible(fn (PurchaseRequest $record): bool => PurchaseRequestResource::canEdit($record)),
                ApplicationTable::cloneAction(
                    PurchaseRequestResource::class,
                    additionalExcludedAttributes: ['pr_number', 'approval_status', 'approval_level', 'approval_setting_id'],
                )
                    ->modalHeading('Clone Purchase Request')
                    ->modalSubmitAction(fn (Action $action): Action => $action->label('Create')->color('primary'))
                    ->databaseTransaction()
                    ->mutateRecordDataUsing(function (array $data, PurchaseRequest $record): array {
                        $selectedRows = PurchaseRequestForm::existingRows($record);

                        return [
                            ...$data,
                            'item_category' => 'critical',
                            'selected_item_rows' => $selectedRows,
                            'items' => PurchaseRequestForm::visibleRows($selectedRows, 'critical', $record->warehouse_id),
                        ];
                    })
                    ->after(function (PurchaseRequest $replica, Action $action): void {
                        $replica->items()->createMany(
                            PurchaseRequestForm::selectedItemsFromFormState($action->getData()),
                        );
                    }),
                DeleteAction::make()->visible(fn (PurchaseRequest $record): bool => PurchaseRequestResource::canDelete($record)),
                RestoreAction::make()->visible(fn (PurchaseRequest $record): bool => PurchaseRequestResource::canRestore($record)),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ]);

        return ApplicationTable::configure(
            $table,
            PurchaseRequestResource::class,
            auditColumnOrder: ['created_at', 'createdBy.name', 'updated_at', 'updatedBy.name', 'deleted_at', 'deletedBy.name'],
        );
    }
}
