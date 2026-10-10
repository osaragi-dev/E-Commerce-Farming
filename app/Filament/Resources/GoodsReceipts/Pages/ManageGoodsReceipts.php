<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageGoodsReceipts extends ManageRecords
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('create')
            ->label('Create Goods Receipt')
            ->icon(Heroicon::Plus)
            ->url(GoodsReceiptResource::getUrl('create'))];
    }
}
