# Niwadu v2

Independent OTA: Next.js/React frontend and Laravel backend. Hotel-scoped access, manual or PMS-managed inventory, and platform-owned payment/PMS configuration.

## Local development

Use Node.js 24, npm, PHP 8.4 and Composer 2. This initial framework setup uses its own local SQLite database. It does not connect to the existing PMS database or live payment gateway.

On this Mac Node 24 and PHP 8.4 are installed via Homebrew; select them for the current shell without changing global defaults:

```sh
export PATH="/opt/homebrew/opt/node@24/bin:/opt/homebrew/opt/php@8.4/bin:$PATH"
```

Install frontend dependencies:

```sh
npm --prefix apps/web ci
```

Install and initialize the API on a fresh checkout:

```sh
cd apps/api
composer install
cp -n .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
cd ../..
```

Run in two terminals from the repository root:

```sh
npm run dev:web
```

```sh
npm run dev:api
```

Frontend process health: http://127.0.0.1:3000/api/health. API framework health: http://127.0.0.1:8000/up. These checks do not certify PMS or payment connectivity. The public homepage is intentionally unimplemented and returns 404; no redesign or placeholder hotel listings have been added. Search indexing is disabled during development and must be configured per environment before launch.

## Verification

```sh
npm run check:web
npm run build:web
npm run test:api
```

## Structure

- `apps/web`: React/Next.js, public and future staff interfaces.
- `apps/api`: Laravel, domain operations, authorization and integration workers.
- `docs/requirements.md`: confirmed product requirements and architecture context.
- `docs/setup-checklist.md`: external accounts and migration inputs.
- `tasks/`: implementation plan and current progress.

Each application owns its dependency lockfile. Never commit credentials, customer exports, database files or build outputs. See AGENTS.md for project boundaries.
