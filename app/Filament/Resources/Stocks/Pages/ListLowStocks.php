<?php

namespace App\Filament\Resources\Stocks\Pages;

use Illuminate\Database\Eloquent\Builder;

class ListLowStocks extends ListStocks
{
    protected static ?string $title = 'Low Stock';

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->lowStock();
    }

    public function getBreadcrumb(): string
    {
        return 'Low Stock';
    }
}
