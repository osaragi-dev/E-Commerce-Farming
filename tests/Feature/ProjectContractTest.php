<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Process;
use Octopy\Filament\Palette\PaletteSwitcherPlugin;

test('backoffice users without a palette preference start with emerald', function (): void {
    $this->actingAs(User::factory()->create());

    $palette = app('filament.palette');

    expect($palette->get())->toBe('emerald')
        ->and($palette->getColors()['primary'])->toBe(Color::Emerald);
});

test('backoffice users keep their selected palette instead of the default', function (): void {
    $this->actingAs(User::factory()->create());
    $palette = app('filament.palette');
    $palette->set('violet');

    expect($palette->get())->toBe('violet')
        ->and($palette->getColors()['primary'])->toBe(Color::Violet);
});

test('admin panel uses the palette switcher theme and sidebar layout', function (): void {
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['require'])->not->toHaveKey('openplain/filament-shadcn-theme')
        ->not->toHaveKey('spatie/laravel-activitylog')
        ->toHaveKey('octopyid/filament-palette')
        ->and(Filament::getPanel('admin')->getColors()['primary'])->toBe(Color::hex('#1f4d3d'))
        ->and(Filament::getPanel('admin')->hasTopNavigation())->toBeFalse()
        ->and(Filament::getPanel('admin')->getGlobalSearchProvider())->toBeNull()
        ->and(Filament::getPanel('admin')->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(Filament::getPanel('admin')->getPlugin('filament-palette'))->toBeInstanceOf(PaletteSwitcherPlugin::class)
        ->and(file_get_contents(resource_path('css/filament/admin/theme.css')))
        ->toContain("@source '../../../../vendor/octopyid/filament-palette/resources';");
});

test('project contract stays outside the Boost managed block after an update', function (): void {
    $before = file_get_contents(base_path('AGENTS.md'));
    $projectRules = str($before)->before('<laravel-boost-guidelines>')->toString();

    expect($projectRules)
        ->toContain('# Project Engineering Contract')
        ->toContain('make:baseline-resource')
        ->toContain('Never read, print, commit, or copy `.env` values.');

    $result = Process::path(base_path())
        ->timeout(180)
        ->env(['APP_ENV' => 'local'])
        ->run([
            PHP_BINARY,
            'artisan',
            'boost:update',
            '--no-interaction',
        ]);

    expect($result->successful())->toBeTrue();

    $after = file_get_contents(base_path('AGENTS.md'));

    expect($after)->toStartWith($projectRules)
        ->and(substr_count($after, '<laravel-boost-guidelines>'))->toBe(1)
        ->and(substr_count($after, '</laravel-boost-guidelines>'))->toBe(1)
        ->and($after)->not->toContain('=== herd rules ===');
});

test('documented project commands and scripts are available', function (): void {
    $commands = Process::path(base_path())
        ->env(['APP_ENV' => 'local'])
        ->run([
            PHP_BINARY,
            'artisan',
            'list',
            '--raw',
        ]);
    $composer = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $package = json_decode(
        file_get_contents(base_path('package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($commands->successful())->toBeTrue()
        ->and($commands->output())->toContain(
            'app:create-super-admin',
            'make:baseline-resource',
            'boost:update',
            'shield:generate',
        )
        ->and($composer['scripts'])->toHaveKeys([
            'setup',
            'dev',
            'test',
            'test:serial',
            'validate:skills',
        ])
        ->and($composer['scripts']['test'])->toBe([
            '@php artisan test --parallel --compact',
        ])
        ->and($composer['scripts']['test:serial'])->toBe([
            '@php artisan test --compact',
        ])
        ->and($package['scripts'])->toHaveKeys(['build', 'dev'])
        ->and(file_exists(base_path('scripts/validate-skills.php')))->toBeTrue();
});

test('Pest uses the guarded bootstrap and raised worker memory limit', function (): void {
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));

    expect($phpunit)->not->toBeFalse()
        ->and((string) $phpunit['bootstrap'])->toBe('tests/bootstrap.php')
        ->and((string) $phpunit->xpath('php/ini[@name="memory_limit"]')[0]['value'])->toBe('512M')
        ->and(file_get_contents(base_path('tests/bootstrap.php')))
        ->toContain(
            "@unlink(__DIR__.'/../bootstrap/cache/config.php');",
            "require __DIR__.'/../vendor/autoload.php';",
        );
});

test('project-local agent skills are present', function (): void {
    foreach (['architect', 'how'] as $skill) {
        expect(file_exists(base_path(".agents/skills/{$skill}/SKILL.md")))->toBeTrue();
    }

    expect(file_get_contents(base_path('.agents/skills/architect/SKILL.md')))
        ->not->toBeEmpty()
        ->and(file_get_contents(base_path('.agents/skills/how/SKILL.md')))
        ->not->toBeEmpty();
});
