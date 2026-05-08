# Migrations

Simple sequential SQL migration system for Folio.

## How it works

- Migration files live in `migrations/` and are named `NNN_description.sql`
- `migrate.php` runs them in order, tracking which have been applied in a `migrations` table
- Migrations run automatically during `docker compose up` (after seed on first run)
- Each migration runs exactly once and is tracked by filename

## Adding a new migration

1. Create a new file: `migrations/NNN_description.sql`
2. Write your SQL (multiple statements are fine, separated by semicolons)
3. Run `php migrate.php` or restart the container

## Design decisions

- Chose flat SQL files over PHP-based migrations for simplicity — this is a small app
- No "down" migrations — for a take-home this is pragmatic; in production you'd want rollbacks
- Migrations run after `seed.php` so the base schema is always fresh from `schema.sql`
