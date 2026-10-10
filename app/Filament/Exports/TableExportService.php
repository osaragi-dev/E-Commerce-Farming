<?php

namespace App\Filament\Exports;

use App\Filament\Support\Rupiah;
use App\Support\Quantity;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonSerializable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TableExportService
{
    private const int PDF_ROW_LIMIT = 2000;

    /**
     * @param  Builder<Model>|Collection<int, Model|array<string, mixed>>  $records
     */
    public function downloadExcel(
        Builder|Collection $records,
        string $model,
        TableExportDefinition $definition,
    ): StreamedResponse {
        return response()->streamDownload(function () use ($records, $definition): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['#', ...array_values($definition->columns)]));

            foreach ($this->spreadsheetRecords($records, $definition) as $index => $record) {
                $values = collect(array_keys($definition->columns))
                    ->map(fn (string $path): bool|float|int|string|null => $this->value($record, $path, true))
                    ->all();
                $writer->addRow(Row::fromValues([$index + 1, ...$values]));
            }

            $writer->close();
        }, $this->filename($model, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  Builder<Model>|Collection<int, Model|array<string, mixed>>  $records
     */
    public function downloadCsv(
        Builder|Collection $records,
        string $model,
        TableExportDefinition $definition,
    ): StreamedResponse {
        return response()->streamDownload(function () use ($records, $definition): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['#', ...array_values($definition->columns)], ',', '"', '');

            foreach ($this->spreadsheetRecords($records, $definition) as $index => $record) {
                $values = collect(array_keys($definition->columns))
                    ->map(fn (string $path): bool|float|int|string|null => $this->value($record, $path, true))
                    ->all();
                fputcsv($output, [$index + 1, ...$values], ',', '"', '');
            }

            fclose($output);
        }, $this->filename($model, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  Builder<Model>|Collection<int, Model|array<string, mixed>>  $records
     */
    public function downloadPdf(
        Builder|Collection $records,
        string $model,
        TableExportDefinition $definition,
    ): StreamedResponse {
        $exportRecords = $this->records($records, $definition, self::PDF_ROW_LIMIT + 1)->values();

        if ($exportRecords->count() > self::PDF_ROW_LIMIT) {
            throw ValidationException::withMessages([
                'export' => 'PDF exports support up to 2,000 rows. Narrow the search or filters and try again.',
            ]);
        }

        return response()->streamDownload(
            function () use ($definition, $exportRecords): void {
                try {
                    echo Pdf::loadView('pdf.table-export', [
                        'definition' => $definition,
                        'records' => $exportRecords,
                        'service' => $this,
                    ])->setPaper('a2', 'landscape')->output();
                } finally {
                    Pdf::clearResolvedInstance('dompdf.wrapper');
                }
            },
            $this->filename($model, 'pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function value(Model|array $record, string $path, bool $forSpreadsheet = false): bool|float|int|string|null
    {
        $value = $this->resolvePath($record, explode('.', $path));

        if ($value instanceof Collection || is_array($value)) {
            $value = collect(Arr::flatten($value instanceof Collection ? $value->all() : $value))
                ->map(fn (mixed $item): mixed => $item instanceof BackedEnum
                    ? (method_exists($item, 'getLabel') ? $item->getLabel() : Str::headline($item->value))
                    : $item)
                ->filter(fn (mixed $item): bool => filled($item))
                ->unique()
                ->join(', ');
        } elseif ($value instanceof BackedEnum) {
            $value = method_exists($value, 'getLabel') ? $value->getLabel() : Str::headline($value->value);
        } elseif ($value instanceof DateTimeInterface) {
            $value = $value->format(str_ends_with($path, '_at') ? 'Y-m-d H:i:s' : 'Y-m-d');
        } elseif ($value instanceof JsonSerializable) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        } elseif (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        if (is_numeric($value) && preg_match('/(?:^|\.)(?:quantity(?:_\w+)?|price_quantity|stock_\w+|initial_stock|low_stock_threshold|system_qty|physical_qty|variance_qty|system_stock|counted_stock|difference)$/', $path)) {
            $value = Quantity::format($value);
        }

        if ($forSpreadsheet && is_string($value) && preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1) {
            return "'{$value}";
        }

        return is_scalar($value) || $value === null ? $value : (string) $value;
    }

    public function pdfValue(
        Model|array $record,
        string $path,
        TableExportDefinition $definition,
    ): bool|float|int|string|null {
        if (in_array($path, $definition->monetary, true)) {
            return Rupiah::formatWithPrefix($this->value($record, $path));
        }

        $value = $this->resolvePath($record, explode('.', $path));

        if ($value instanceof DateTimeInterface) {
            return $value->format(
                in_array($path, $definition->dateOnly, true) ? 'd M Y' : 'd M Y H:i',
            );
        }

        return $this->value($record, $path);
    }

    /**
     * @param  Builder<Model>|Collection<int, Model|array<string, mixed>>  $records
     * @return Collection<int, Model|array<string, mixed>>
     */
    private function records(
        Builder|Collection $records,
        TableExportDefinition $definition,
        ?int $limit = null,
    ): Collection {
        if ($records instanceof Collection) {
            $records->each(function (Model|array $record) use ($definition): void {
                if ($record instanceof Model) {
                    $record->loadMissing($definition->relationships);
                }
            });

            return $limit === null ? $records : $records->take($limit);
        }

        $query = $records->with($definition->relationships);

        return $limit === null ? $query->get() : $query->limit($limit)->get();
    }

    /**
     * @param  Builder<Model>|Collection<int, Model|array<string, mixed>>  $records
     * @return iterable<int, Model|array<string, mixed>>
     */
    private function spreadsheetRecords(
        Builder|Collection $records,
        TableExportDefinition $definition,
    ): iterable {
        if ($records instanceof Collection) {
            return $records
                ->each(function (Model|array $record) use ($definition): void {
                    if ($record instanceof Model) {
                        $record->loadMissing($definition->relationships);
                    }
                })
                ->values();
        }

        return $records->with($definition->relationships)->lazy(500);
    }

    /**
     * Resolve dotted attributes through both singular relations and relation collections.
     *
     * @param  array<int, string>  $segments
     */
    private function resolvePath(mixed $target, array $segments): mixed
    {
        if ($segments === []) {
            return $target;
        }

        if ($target instanceof Collection || (is_array($target) && array_is_list($target))) {
            return collect($target)
                ->map(fn (mixed $item): mixed => $this->resolvePath($item, $segments))
                ->all();
        }

        $segment = array_shift($segments);

        return $this->resolvePath(data_get($target, $segment), $segments);
    }

    private function filename(string $model, string $extension): string
    {
        $name = Str::of($model)->classBasename()->kebab()->plural();

        return "{$name}-".now()->format('Ymd-His').".{$extension}";
    }
}
