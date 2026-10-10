<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('make:baseline-resource
    {name : Singular model name in StudlyCase}
    {--base-path= : Alternate output root, primarily for isolated validation}')]
#[Description('Generate a modal-first Filament resource with the application baseline')]
class MakeBaselineResource extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->error('The name must be singular StudlyCase, for example CustomerProfile.');

            return self::FAILURE;
        }

        $root = rtrim((string) ($this->option('base-path') ?: base_path()), DIRECTORY_SEPARATOR);
        $plural = Str::pluralStudly($name);
        $table = Str::snake(Str::pluralStudly($name));
        $variable = Str::camel($name);
        $timestamp = now()->format('Y_m_d_His');
        $replacements = [
            '{{ class }}' => $name,
            '{{ plural }}' => $plural,
            '{{ table }}' => $table,
            '{{ variable }}' => $variable,
        ];

        $targets = [
            "app/Models/{$name}.php" => 'model.stub',
            "database/factories/{$name}Factory.php" => 'factory.stub',
            "database/migrations/{$timestamp}_create_{$table}_table.php" => 'migration.stub',
            "app/Filament/Resources/{$plural}/{$name}Resource.php" => 'resource.stub',
            "app/Filament/Resources/{$plural}/Schemas/{$name}Form.php" => 'form.stub',
            "app/Filament/Resources/{$plural}/Schemas/{$name}Infolist.php" => 'infolist.stub',
            "app/Filament/Resources/{$plural}/Tables/{$plural}Table.php" => 'table.stub',
            "app/Filament/Resources/{$plural}/Pages/Manage{$plural}.php" => 'manage.stub',
            "tests/Feature/Filament/{$name}ResourceTest.php" => 'test.stub',
        ];

        $existing = collect(array_keys($targets))
            ->filter(fn (string $path): bool => File::exists("{$root}/{$path}"));

        if ($existing->isNotEmpty()) {
            $this->error('Nothing was generated because these targets already exist:');
            $existing->each(fn (string $path) => $this->line(" - {$path}"));

            return self::FAILURE;
        }

        foreach ($targets as $target => $stub) {
            $contents = File::get(base_path("stubs/baseline-resource/{$stub}"));
            $path = "{$root}/{$target}";

            File::ensureDirectoryExists(dirname($path));
            File::put($path, str_replace(array_keys($replacements), array_values($replacements), $contents));
            $this->components->task("Created {$target}");
        }

        $this->newLine();
        $this->info("Baseline resource {$name} generated. Review its fields before running migrations.");

        return self::SUCCESS;
    }
}
