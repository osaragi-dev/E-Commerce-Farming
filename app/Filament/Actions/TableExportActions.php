<?php

namespace App\Filament\Actions;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Exports\TableExportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class TableExportActions
{
    /**
     * @param  class-string<HasTableExport>  $resource
     */
    public static function group(string $resource, ?TableExportDefinition $definition = null, ?string $model = null, ?string $exportPermission = null): ActionGroup
    {
        return ActionGroup::make([
            self::pageAction('Excel', 'success', 'heroicon-o-table-cells', 'downloadExcel', $resource, $definition, $model, $exportPermission),
            self::pageAction('CSV', 'info', 'heroicon-o-document-text', 'downloadCsv', $resource, $definition, $model, $exportPermission),
            self::pageAction('PDF', 'danger', 'heroicon-o-document', 'downloadPdf', $resource, $definition, $model, $exportPermission),
        ])
            ->label('Export')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => self::canExport($resource, $exportPermission));
    }

    /**
     * @param  class-string<HasTableExport>  $resource
     * @return list<BulkAction>
     */
    public static function selected(string $resource, ?TableExportDefinition $definition = null, ?string $model = null, ?string $exportPermission = null): array
    {
        return [
            self::selectedAction('Excel', 'success', 'heroicon-o-table-cells', 'downloadExcel', $resource, $definition, $model, $exportPermission),
            self::selectedAction('CSV', 'info', 'heroicon-o-document-text', 'downloadCsv', $resource, $definition, $model, $exportPermission),
            self::selectedAction('PDF', 'danger', 'heroicon-o-document', 'downloadPdf', $resource, $definition, $model, $exportPermission),
        ];
    }

    /**
     * @param  'downloadExcel'|'downloadCsv'|'downloadPdf'  $serviceMethod
     * @param  class-string<HasTableExport>  $resource
     */
    private static function pageAction(
        string $format,
        string $color,
        string $icon,
        string $serviceMethod,
        string $resource,
        ?TableExportDefinition $definition,
        ?string $model,
        ?string $exportPermission,
    ): Action {
        return Action::make('export'.Str::studly(Str::lower($format)))
            ->label("Export {$format}")
            ->icon($icon)
            ->color($color)
            ->authorize(fn (): bool => self::canExport($resource, $exportPermission))
            ->action(function (HasTable $livewire) use ($resource, $serviceMethod, $definition, $model) {
                $records = $livewire->getTableRecords();
                $records = $records instanceof Collection ? $records : collect($records->items());

                return app(TableExportService::class)->{$serviceMethod}(
                    $records->values(),
                    $model ?? $resource::getModel(),
                    self::visibleColumnsDefinition($livewire, $resource, $definition),
                );
            });
    }

    /**
     * @param  'downloadExcel'|'downloadCsv'|'downloadPdf'  $serviceMethod
     * @param  class-string<HasTableExport>  $resource
     */
    private static function selectedAction(
        string $format,
        string $color,
        string $icon,
        string $serviceMethod,
        string $resource,
        ?TableExportDefinition $definition,
        ?string $model,
        ?string $exportPermission,
    ): BulkAction {
        return BulkAction::make('exportSelected'.Str::studly(Str::lower($format)))
            ->label("Export Selected {$format}")
            ->icon($icon)
            ->color($color)
            ->authorize(fn (): bool => self::canExport($resource, $exportPermission))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, HasTable $livewire) use ($resource, $serviceMethod, $definition, $model) {
                return app(TableExportService::class)->{$serviceMethod}(
                    $records,
                    $model ?? $resource::getModel(),
                    self::visibleColumnsDefinition($livewire, $resource, $definition),
                );
            });
    }

    /** @param class-string<HasTableExport> $resource */
    private static function visibleColumnsDefinition(
        HasTable $livewire,
        string $resource,
        ?TableExportDefinition $definition,
    ): TableExportDefinition {
        $visibleColumnPaths = collect($livewire->getTable()->getVisibleColumns())
            ->map(fn (Column $column): string => $column->getName())
            ->reject(fn (string $path): bool => $path === 'row_number')
            ->values()
            ->all();

        return ($definition ?? $resource::exportDefinition())->onlyColumns($visibleColumnPaths);
    }

    /** @param class-string<HasTableExport> $resource */
    private static function canExport(string $resource, ?string $exportPermission): bool
    {
        if ($exportPermission !== null) {
            return $resource::canViewAny() && Gate::allows($exportPermission);
        }

        /** @var class-string<Model> $model */
        $model = $resource::getModel();

        return Gate::allows('Export:'.Str::afterLast($model, '\\'));
    }
}
