<?php

namespace App\Filament\Exports;

final readonly class TableExportDefinition
{
    private const array INTERNAL_COLUMNS = [
        'id',
        'created_by',
        'updated_by',
        'deleted_by',
        'previous_record_status',
        'password',
        'remember_token',
    ];

    public string $title;

    /** @var array<string, string> */
    public array $columns;

    /** @var list<string> */
    public array $relationships;

    /** @var list<string> */
    public array $monetary;

    /** @var list<string> */
    public array $dateOnly;

    /**
     * @param  array<string, string>  $columns
     * @param  list<string>  $relationships
     * @param  list<string>  $monetary
     * @param  list<string>  $dateOnly
     */
    public function __construct(
        string $title,
        array $columns,
        array $relationships = [],
        array $monetary = [],
        array $dateOnly = [],
    ) {
        $this->title = $title;
        $this->columns = collect($columns)
            ->reject(fn (string $label, string $path): bool => in_array($path, self::INTERNAL_COLUMNS, true))
            ->all();
        $this->relationships = $relationships;
        $this->monetary = $monetary;
        $this->dateOnly = $dateOnly;
    }

    /** @param list<string> $columnPaths */
    public function onlyColumns(array $columnPaths): self
    {
        $columns = collect($columnPaths)
            ->mapWithKeys(fn (string $path): array => array_key_exists($path, $this->columns)
                ? [$path => $this->columns[$path]]
                : [])
            ->all();

        return new self(
            title: $this->title,
            columns: $columns,
            relationships: $this->relationships,
            monetary: array_values(array_intersect($this->monetary, array_keys($columns))),
            dateOnly: array_values(array_intersect($this->dateOnly, array_keys($columns))),
        );
    }
}
