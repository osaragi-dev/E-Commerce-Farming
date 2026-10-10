<?php

namespace App\Console\Commands;

use App\Models\Repack;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

#[Signature('convert:backfill-stock-out')]
#[Description('Count historical Convert source quantities in Out without changing available stock or mutations')]
class BackfillConvertStockOut extends Command
{
    public function handle(): int
    {
        $applied = 0;
        $failed = 0;

        foreach (Repack::withTrashed()->whereNotNull('applied_at')->whereNull('stock_out_applied_at')->whereNull('cancelled_at')->lazyById(100) as $record) {
            try {
                if ($record->applyStockOut()) {
                    $applied++;
                }
            } catch (ValidationException|ModelNotFoundException $exception) {
                $failed++;
                $this->error("Convert {$record->repack_number}: {$exception->getMessage()}");
            }
        }

        $this->info("Applied Out to {$applied} Convert transaction(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
