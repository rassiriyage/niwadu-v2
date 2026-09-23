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

## Hotel management

Open http://localhost:3000/admin after starting both services. Create your first platform administrator from `apps/api`:

```sh
php artisan niwadu:create-administrator you@example.com --name="Your name"
```

The command asks for a hidden password (at least 12 characters). There are no default production credentials or public registration. A successful password reset revokes prior authenticated sessions on their next API request, including session introspection. Existing users cannot be promoted by this command.

Administrators can create private hotel drafts, edit profiles, and grant/revoke hotel staff access. Hotel managers can edit only their assigned profiles and view their staff roster; reservations, inventory and viewer roles currently have read-only profile access. Booking and inventory operations are not implemented yet. Onboarding employees are limited to drafts they created.

To provision a Niwadu onboarding employee, an authorized operator runs this dedicated command from `apps/api` against the intended Niwadu database:

```sh
php artisan niwadu:create-onboarding-employee employee@example.com --name="Employee name"
```

It prompts for a hidden password of 12–1024 characters and hashes it; omit `--name` to enter the name interactively. Use the employee’s real email and a unique password, then share the credentials through your approved secure channel. The command sends no email, assigns no hotel membership, and never changes an existing account. The employee signs in at `/admin` to create and resume their own hotel drafts; this does not grant administrator powers or payment/PMS configuration access. Keep local development on its own Niwadu database with `MAIL_MAILER=log`.

Hotel staff provisioning is separate: new accounts receive a single-use password link; administrators can resend it from the staff roster. Local mail uses `MAIL_MAILER=log`, so development links appear in `apps/api/storage/logs/laravel.log`. Configure a real mail provider and `FRONTEND_URL` before inviting real staff. A failed delivery does not revoke the saved membership: refresh the roster and use **Send password link** to retry.

The seven-step onboarding wizard is available after creating a hotel, or through **Continue hotel setup** on its profile. It autosaves partial drafts, remembers the current step, accepts private hotel/room photographs, and records room types, indicative LKR rates, policies and staff access. Concurrent edits return a conflict instead of overwriting newer work. A conflict preserves input and offers copying unsaved values before explicitly discarding them to reload the latest draft. Validation messages link to the affected fields; **Save and continue** focuses an error summary when corrections are needed. Browser Back/Forward can recover pending form values from this tab’s user/hotel-scoped recovery copy, retaining the original version check. This copy is cleared after successful save, explicit discard or sign-out; it is not a server backup. If saving fails during sign-out, choose **Keep editing** or **Discard unsaved changes and sign out**. Profile PATCH requests must include the `version` returned by the hotel API.

Rooms and rates in this wizard are draft inputs, not sellable inventory. The final review lists missing information and launch requirements; there is no publication endpoint. Room photos currently use captions to identify the room; structured room/media mapping and the public listing preview belong to the catalog work. Photos use private local storage in development (JPG/PNG/WebP, up to 5 MB and 50 photos per hotel). Configure PHP/web-server upload limits to support 5 MB files and private object storage before staging.

The public homepage, live inventory, PMS adapters and PAYable integration remain upcoming work. Payment/PMS configuration is excluded from hotel profile writes.

## Verification

```sh
npm run check:web
npm run build:web
npm run test:api
npm --prefix apps/web run test:e2e
```

Browser tests require Chromium (`cd apps/web && npx playwright install chromium`). They start local servers on ports 3101/8101 and use a separate SQLite test database with guarded test-only accounts.

## Structure

- `apps/web`: React/Next.js, public and staff interfaces.
- `apps/api`: Laravel, domain operations, authorization and integration workers.
- `docs/requirements.md`: confirmed product requirements and architecture context.
- `docs/setup-checklist.md`: external accounts and migration inputs.
- `tasks/`: implementation plan and current progress.

Each application owns its dependency lockfile. Never commit credentials, customer exports, database files or build outputs. See AGENTS.md for project boundaries.
