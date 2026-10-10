<?php

namespace App\Filament\Contracts;

use App\Filament\Exports\TableExportDefinition;

interface HasTableExport
{
    public static function exportDefinition(): TableExportDefinition;
}
