# PIT-Launchpad

A Laravel 13 application.

## Stack

- **Framework:** Laravel 13 (PHP 8.4+)
- **Database:** PostgreSQL
- **Testing:** [Pest](https://pestphp.com/) 4
- **Frontend build:** Vite

Sessions, cache, and queues are all backed by the database.

## Getting started

```bash
# Install dependencies
composer install
npm install

# Configure environment
cp .env.example .env
php artisan key:generate

# Create the PostgreSQL database referenced by .env, then migrate
php artisan migrate

# Run the app
composer run dev
```

## Testing

```bash
./vendor/bin/pest
# or
php artisan test
```

See [CLAUDE.md](CLAUDE.md) for architecture notes and project conventions.
