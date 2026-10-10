<?php

namespace App\Filament\Resources\Stocks\Pages;

use App\Filament\Resources\Stocks\StockResource;
use Filament\Resources\Pages\Page;

class StockCategories extends Page
{
    protected static string $resource = StockResource::class;

    protected string $view = 'filament.resources.stocks.pages.stock-categories';

    public function getTitle(): string
    {
        return 'Stocks';
    }
}
