<?php

namespace App\Filament\Resources\Vendors\Tables;

use App\Enums\RecordStatus;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Tables\ApplicationTable;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn ($query) => $query->with(['createdBy', 'updatedBy', 'deletedBy', 'approvalSetting.levels']))
            ->columns([
                TextColumn::make('row_number')->label('No')->rowIndex(),
                TextColumn::make('approval_progress')
                    ->label('Approval Progress')
                    ->state(fn ($record): string => $record->approvalProgressLabel())
                    ->badge()
                    ->color(fn ($record): string => $record->approval_status?->getColor() ?? 'gray'),
                TextColumn::make('vendor_code')->label('Vendor Code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('contact_person')->searchable()->placeholder('-'),
                TextColumn::make('phone')->searchable()->placeholder('-'),
                TextColumn::make('email')->searchable()->placeholder('-'),
                TextColumn::make('address')->limit(35)->placeholder('-')->toggleable(),
                TextColumn::make('tax_id')->label('Tax ID')->placeholder('-')->toggleable(),
                TextColumn::make('bank_name')->placeholder('-')->toggleable(),
                TextColumn::make('lead_time_days')->label('Lead Time (Days)')->sortable(),
                TextColumn::make('payment_terms')->placeholder('-')->toggleable(),
                TextColumn::make('is_active')->label('Active')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('approval_logs_count')->label('Log History')->counts('approvalLogs')->sortable(),
                TextColumn::make('record_status')->label('Status')->badge()->sortable(),
                TextColumn::make('notes')->limit(35)->placeholder('-')->toggleable(),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('-')->sortable(),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable(),
                TextColumn::make('updatedBy.name')->label('Updated By')->placeholder('-')->sortable(),
                TextColumn::make('updated_at')->label('Updated At')->dateTime()->sortable(),
                TextColumn::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletedBy.name')->label('Deleted By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('record_status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                DateRangeFilter::make('created_at')->label('Created Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record): string => VendorResource::getUrl('view', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                TableExportActions::group(VendorResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(VendorResource::class),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);

        return ApplicationTable::configure($table, VendorResource::class);
    }
}
