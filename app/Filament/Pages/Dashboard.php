<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LowStockWidget;
use App\Filament\Widgets\StockOverview;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFilters;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    use HasFilters;

    public function getWidgets(): array
    {
        return array_values(array_filter(
            parent::getWidgets(),
            fn (string|WidgetConfiguration $widget): bool => ! in_array(
                $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
                [StockOverview::class, LowStockWidget::class],
                true,
            ),
        ));
    }

    private const TIME_RANGE_OPTIONS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'custom' => 'Custom range',
    ];

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->filters = $this->normalizeFilters($this->filters ?? []);
    }

    public function updatedFilters(): void
    {
        $this->filters = $this->normalizeFilters($this->filters ?? []);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('filters')
                ->label('Filters')
                ->icon('heroicon-o-adjustments-horizontal')
                ->modalHeading('Filters')
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitActionLabel('Submit')
                ->modalCancelActionLabel('Cancel')
                ->schema(fn (Schema $schema): Schema => $schema->columns(1)->components(self::filterComponents()))
                ->fillForm(fn (): array => $this->filters ?? self::defaultFilters())
                ->action(function (array $data): void {
                    $this->filters = $this->normalizeFilters($data);
                }),
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultFilters(): array
    {
        return [
            'time_range' => 'today',
            'start_date' => null,
            'end_date' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $timeRange = $filters['time_range'] ?? 'today';

        return [
            'time_range' => is_string($timeRange) && array_key_exists($timeRange, self::TIME_RANGE_OPTIONS) ? $timeRange : 'today',
            'start_date' => is_string($filters['start_date'] ?? null) ? $filters['start_date'] : null,
            'end_date' => is_string($filters['end_date'] ?? null) ? $filters['end_date'] : null,
        ];
    }

    /** @return array<int, Component> */
    private static function filterComponents(): array
    {
        return [
            Select::make('time_range')
                ->label('Time Range')
                ->options(self::TIME_RANGE_OPTIONS)
                ->required()
                ->live(),
            DatePicker::make('start_date')
                ->label('Start Date')
                ->required(fn (Get $get): bool => $get('time_range') === 'custom')
                ->visible(fn (Get $get): bool => $get('time_range') === 'custom'),
            DatePicker::make('end_date')
                ->label('End Date')
                ->required(fn (Get $get): bool => $get('time_range') === 'custom')
                ->afterOrEqual('start_date')
                ->visible(fn (Get $get): bool => $get('time_range') === 'custom'),
        ];
    }
}
