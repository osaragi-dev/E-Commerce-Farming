# Laravel Filament Boilerplate

An opinionated starting point for internal tools and admin applications built with Laravel 13 and Filament 5. It provides a reusable, domain-neutral baseline for secure modal-first CRUD, record lifecycle management, authorization, exports, and automated testing.

## Quick start

### Requirements

- PHP 8.4 or newer
- PostgreSQL 15 or newer
- Node.js 22 or newer
- Composer 2
- Laravel Herd for the documented local URL

### Create a project

1. Mark this repository as a GitHub template and select **Use this template**.
2. Clone the new repository into Laravel Herd.
3. Create a PostgreSQL database for the application.
4. Copy `.env.example` to `.env` and update the database settings.
5. Run:

```bash
composer setup
```

The setup script installs PHP and JavaScript dependencies, generates the application key, migrates and seeds the database, builds frontend assets, refreshes Laravel Boost guidance, and interactively creates the first super administrator.

Open [https://farming.test/admin](https://farming.test/admin) and sign in with the administrator account created during setup.

For non-interactive setup, provide `ADMIN_NAME`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD` through the process environment. The password must contain at least six characters. An existing email address is rejected without changing its user.

## What is included

- Laravel 13, Filament 5, Livewire 4, and Tailwind CSS 4
- PostgreSQL application runtime with PostgreSQL and SQLite test coverage
- Filament Shield role and permission management
- UUID v7 primary keys and actor auditing
- Draft, Saved, soft-deleted Deleted, and restore workflows
- Modal-first resource pages with shared table behavior
- XLSX, CSV, and PDF page or selected-row exports
- A baseline resource generator
- Pest 4 tests and GitHub Actions CI
- A removable Products example

## Project structure

```text
app/
├── Console/Commands/              # Project-specific Artisan commands
├── Filament/
│   ├── Actions/                   # Shared lifecycle and export actions
│   ├── Exports/                   # XLSX, CSV, and PDF export support
│   ├── Resources/                 # Filament resources and their UI schemas
│   └── Tables/                    # Shared table configuration
├── Models/Concerns/               # UUID and record lifecycle model traits
└── Providers/Filament/            # Admin panel configuration
database/
├── factories/                     # Test and seeder model factories
├── migrations/                    # PostgreSQL- and SQLite-compatible schema
└── seeders/                       # Baseline roles and example data
resources/css/filament/admin/      # Filament Tailwind theme
stubs/baseline-resource/           # Templates used by the resource generator
tests/                             # Pest feature and unit tests
.ai/skills/                        # Canonical project-specific agent skills
```

## Key concepts

### Standard resources

Standard admin resources are modal-first and share table behavior through `ApplicationTable`. They implement `HasTableExport` and expose export fields with `TableExportDefinition`.

Create them with the project generator instead of copying an existing resource:

```bash
php artisan make:baseline-resource Customer --no-interaction
```

The command creates the model, migration, factory, Filament resource, schemas, table, page, and Pest test skeleton. It never runs migrations and refuses to overwrite any target.

Review the generated fields, then run:

```bash
php artisan migrate --no-interaction
php -d memory_limit=512M artisan test --compact --filter=Customer
vendor/bin/pint --format agent
```

### Record lifecycle

Lifecycle models use `HasRecordLifecycle`, `HasUuidPrimaryKey`, and `SoftDeletes`. Draft identifiers may be reused. Identifiers must be unique among active Saved records, ignoring case and surrounding whitespace. Restoring a conflicting Saved record returns it as a Draft.

Do not add force-delete actions to baseline resources; Deleted records remain recoverable.

### Authorization and exports

Filament Shield protects resources. Export access requires the matching `Export:{Resource}` permission.

Standard tables export the active page or selected rows as XLSX, CSV, or PDF. PDF exports are limited to 2,000 records. Export definitions exclude internal identifiers, and spreadsheet-like values beginning with `=`, `+`, `-`, or `@` are neutralized.

### Currency and locale

The interface and exports use English, the application timezone is `Asia/Jakarta`, and IDR fields should reuse the shared `RupiahInput`, `RupiahColumn`, and `Rupiah` helpers.

## Common tasks

### Development

Herd serves PHP; the development command only starts the queue worker, log viewer, and Vite:

```bash
composer run dev
```

### Verification

```bash
# Targeted test
php -d memory_limit=512M artisan test --compact --filter=Lifecycle

# Complete suite
composer test

# Format PHP
vendor/bin/pint --format agent

# Build frontend assets
npm run build

# Refresh managed Laravel Boost guidance
php artisan boost:update --no-interaction

# Validate project skills
composer validate:skills
```

GitHub Actions runs Composer validation, Pint checks, the frontend build, Boost and skill validation, and Pest against SQLite and PostgreSQL 16.

### Remove the Products example

After generating the first real resource:

1. Remove `app/Models/Product.php`.
2. Remove `app/Filament/Resources/Products`.
3. Remove `database/factories/ProductFactory.php`.
4. Remove the Products migration and `database/seeders/ProductSeeder.php`.
5. Remove the Product seeder call from `database/seeders/DatabaseSeeder.php`.
6. Remove Product-specific policies and tests.
7. Regenerate Shield permissions, migrate a disposable local database from scratch, and run the full test suite:

```bash
php artisan shield:generate --all --panel=admin --no-interaction
php artisan migrate:fresh --seed --no-interaction
composer test
```

## Configuration

Start from `.env.example`. The main local settings are:

| Variable | Purpose | Default example |
| --- | --- | --- |
| `APP_NAME` | Name shown in the application | `Laravel Filament Boilerplate` |
| `APP_URL` | Herd application URL | `https://farming.test` |
| `APP_TIMEZONE` | Application timezone | `Asia/Jakarta` |
| `DB_CONNECTION` | Runtime database driver | `pgsql` |
| `DB_DATABASE` | Local PostgreSQL database | `boilerplate` |
| `ADMIN_NAME` | Non-interactive initial administrator name | unset |
| `ADMIN_EMAIL` | Non-interactive initial administrator email | unset |
| `ADMIN_PASSWORD` | Non-interactive initial administrator password | unset |

Never commit `.env` or credentials.

## Engineering guidance

`AGENTS.md` is the engineering contract for this repository. Laravel Boost replaces only its managed `<laravel-boost-guidelines>` block. Claude Code also reads `CLAUDE.md`.

Canonical project skills live in `.ai/skills` and are synchronized for supported agents. Use `grill-me` to stress-test a plan and `laravel-mcp` only when explicitly working on Laravel MCP functionality.

## Troubleshooting

### The admin page is unavailable

Confirm the project is linked in Herd, `APP_URL` is `https://farming.test`, and migrations have run. Do not start `php artisan serve` for this project.

### Vite cannot find an asset

Build the frontend assets:

```bash
npm run build
```

Use `composer run dev` while actively changing frontend files.

### Database tests fail locally

Run the isolated SQLite suite first to separate application failures from local PostgreSQL configuration:

```bash
composer test
```

### Generated permissions are stale

Regenerate Shield permissions after adding or removing resources:

```bash
php artisan shield:generate --all --panel=admin --no-interaction
```

## Deployment

This template intentionally does not include deployment or continuous-delivery configuration. Add deployment only after choosing the target environment and its secret-management strategy.

## License

This project is licensed under the MIT License as declared in `composer.json`.

Last updated: August 11, 2026.
