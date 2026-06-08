# CLAUDE.md

Guidance for Claude Code (and humans) working in this repository.

## Project

PIT-Launchpad is a freshly scaffolded **Laravel 13** application. The Laravel
project lives at the repository root (`composer.json`, `artisan`, `app/`,
`routes/`, etc. are all top-level).

## Stack

| Concern        | Choice                                  |
| -------------- | --------------------------------------- |
| Language       | PHP 8.4+                                |
| Framework      | Laravel 13                              |
| Database       | PostgreSQL (`pgsql` connection)         |
| Sessions       | `database` driver                       |
| Cache          | `database` store                        |
| Queue          | `database` connection                   |
| Testing        | Pest 4 (`pestphp/pest`)                 |
| Asset bundling | Vite                                    |
| Code style     | Laravel Pint                            |

Because sessions, cache, and queues are database-backed, `php artisan migrate`
creates the supporting `sessions`, `cache`, and `jobs` tables out of the box.

## Common commands

```bash
# Dependencies
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Database (PostgreSQL must be running and the database created)
php artisan migrate

# Run everything (server, queue, logs, Vite) together
composer run dev

# Tests
./vendor/bin/pest            # or: php artisan test
./vendor/bin/pest --filter=SomeTest

# Lint / format
./vendor/bin/pint            # apply fixes
./vendor/bin/pint --test     # check only
```

## Testing conventions

- Tests are written with **Pest**, not the PHPUnit class style. Prefer the
  functional API:

  ```php
  test('it does the thing', function () {
      expect(true)->toBeTrue();
  });
  ```

- Feature tests live in `tests/Feature/`, unit tests in `tests/Unit/`.
- Shared setup, traits (e.g. `RefreshDatabase`), and custom expectations belong
  in `tests/Pest.php`.
- The test suite runs against an in-memory SQLite database and array drivers as
  configured in `phpunit.xml` — tests do not require a running PostgreSQL
  instance.

## Conventions

- Follow standard Laravel structure and naming. Run Pint before committing.
- Never commit secrets. `.env` is git-ignored; keep `.env.example` as the
  documented template (any new config key should be added there too).
- Add new database changes as migrations in `database/migrations/`.
