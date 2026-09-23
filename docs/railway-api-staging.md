# Private API staging candidate on Railway

Preparation only, 2026-09-23. No Railway configuration, database connection, migration or deployment has been performed. The user reports creating PostgreSQL; its service name, version and connectivity remain unverified. Existence of the Laravel API service is also unverified.

Use the approved administration code at `07309ccb9e665522c6c24b18c5d89ad753a14547` (code equivalent to `61b0913`), or a coordinator integration commit containing those fixes. Public preview commit `e65d817` contains an older administration baseline and is **not** the approved private-backend deployment candidate.

## Service layout and variables

Use separate frontend and Laravel services in the same dedicated staging environment. Set the API service root to `/apps/api`, Railpack builder, one replica and port `8080`. The frontend keeps its existing same-origin `/api/v1/*` rewrite; the API upstream is private Railway networking. Do not expose a separate public API domain for this candidate. This topology must first be checked against the actual frontend hosting/network arrangement. [Railway monorepos](https://docs.railway.com/guides/deploying-a-monorepo) and [private service references](https://docs.railway.com/networking/domains/working-with-domains).

Candidate API variables below contain placeholders, not credentials. Replace `Postgres` with the actual database service alias. `APP_URL` and `FRONTEND_URL` are the same externally visible frontend HTTPS origin, with no trailing slash; the API is served under that origin's `/api/v1` path.

```dotenv
APP_NAME=Niwadu
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<staging-frontend-host>
FRONTEND_URL=https://<staging-frontend-host>
APP_KEY=<one securely generated persistent Laravel key>
PORT=8080
DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}
SESSION_DRIVER=database
SESSION_COOKIE=niwadu_staging_session
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MAIL_MAILER=log
MAIL_LOG_CHANNEL=stderr
LOG_CHANNEL=stderr
LOG_LEVEL=debug
COMPOSER_NO_DEV=1
RAILPACK_SKIP_MIGRATIONS=true
RAILPACK_PHP_EXTENSIONS=pdo_pgsql,gd
```

Leave `SESSION_DOMAIN` unset: the browser receives a host-only cookie through the frontend. Do not configure a shared production cookie domain. Keep the existing CSRF/session flow; no wildcard CORS or CSRF exclusions are needed. `DB_URL` is the name read by `config/database.php`; setting only `DATABASE_URL` on the API service does not wire it up. Use the database's private connection reference, not its public proxy URL. Confirm SSL requirements against that actual connection before changing `DB_SSLMODE` from its current `prefer` default. [Railway Laravel guidance](https://docs.railway.com/guides/laravel) and [PostgreSQL variables](https://docs.railway.com/databases/postgresql).

Generate `APP_KEY` once through an approved secure operator workflow, store it as a service secret, and preserve it across builds/restarts. Never place it in this document, source control or chat; never run key generation during startup. Staging must have its own database and key.

On the frontend service, the candidate is `API_ORIGIN=http://${{API.RAILWAY_PRIVATE_DOMAIN}}:8080`, replacing `API` with the actual Laravel service alias. It is a server-only variable, with no `NEXT_PUBLIC_` prefix. Rebuild/redeploy the frontend when changing its rewrite target. The frontend must actually run in a network that can reach this private domain; an externally hosted public preview cannot use this value.

Log mail is deliberately limited to disposable staging accounts. The log transport writes reset/setup links, including usable tokens, at debug severity; `LOG_LEVEL=info` would silently hide those messages. With the above configuration, access to Railway logs must be restricted to approved operators and retention kept bounded. Never paste mail payloads into tasks or artifacts. No SMTP credentials, live invitations or production identities are part of this preparation. Once real users are proposed, revisit delivery and token-bearing log handling before enabling them. The present notification flow is synchronous, so this slice needs no queue worker or scheduler.

## Minimal changes for coordinator integration

These are proposed changes, **not implemented deployment files**:

1. Align `apps/api/composer.json`'s PHP requirement with the verified PHP 8.4 runtime (currently `^8.3`), refresh lock metadata without dependency upgrades, and verify the resulting Railpack image is PHP 8.4 with `pdo_pgsql` and `gd`. Do not invent a runtime version environment variable: Railpack derives PHP selection from Composer requirements.
2. Add a small `apps/api/start-container.sh` override. Fail startup if the persistent key, PostgreSQL connection or expected volume is absent; check the photo directory is writable. Clear only the configuration cache, then build configuration/route/event/view caches at runtime. Finish with `exec docker-php-entrypoint --config /Caddyfile --adapter caddyfile`. Do not run migrations, seeding, `key:generate`, `storage:link` or `optimize:clear` there. The latter clears application cache and can reset shared rate-limit state.
3. Add an API `php.ini` with `upload_max_filesize=5M` and `post_max_size=8M`, matching the existing one-photo request and 5120 KiB application limit. Verify an allowed photo near the limit passes the complete proxy chain. Keep application file-type, dimension and authorization checks intact.
4. Configure trusted proxies in `apps/api/bootstrap/app.php` after observing the actual ingress chain. Laravel currently has no proxy configuration. The private HTTP hop must preserve the external HTTPS scheme for secure request/CSRF behavior. Restrict trusted sources to the verified immediate proxy network; if dynamic addresses require trusting all immediate peers, first establish that API ingress is private and that only trusted peers can reach it. Trust only headers whose handling is verified. Do not blindly enable forwarded-host/client-IP trust.

Railpack supports custom startup and PHP configuration files. Its documentation describes migration/seeding behavior, but the inspected startup source currently calls only `migrate --force`, then `storage:link`, `optimize:clear` and `optimize`. Disable startup migration behavior explicitly and use the override to avoid the other unwanted effects. Recheck generated image behavior when implementing; upstream `main` can change. [Railpack PHP](https://railpack.com/languages/php/), [startup source](https://raw.githubusercontent.com/railwayapp/railpack/main/core/providers/php/start-container.sh), [provider source](https://raw.githubusercontent.com/railwayapp/railpack/main/core/providers/php/php.go).

The proxy acceptance test must check external scheme, host and client address through Railway → Next → Laravel, including spoofed incoming forwarding headers. Railway documents `X-Forwarded-Proto: https`; that alone does not establish sanitization across the Next hop. Existing login limits use `$request->ip()`. Until the client-IP contract is verified, they may bucket all users by the frontend address; do not remove or relax them to compensate. Retain a closed staging audience until the proxy behavior is settled. [Railway proxy headers](https://docs.railway.com/networking/public-networking/specs-and-limits).

## Persistent photos

Attach a dedicated API volume at `/app/storage/app/private`. With `/apps/api` as the service root, Railpack places the application at `/app`. `HotelPhotoController` explicitly reads, writes and deletes on the `local` disk rooted at `storage/app/private`; changing `FILESYSTEM_DISK` or adding S3 variables alone will not move photos. Do not expose this directory through a public symlink or mount over the entire application/storage tree.

Confirm the runtime user can write the mounted directory and reject startup when the expected mount is missing. Volumes are attached at runtime, not during build or pre-deploy; ownership may need explicit operator configuration. Keep this initial API to one replica. Back up this volume separately from PostgreSQL and coordinate restoration of photo records and files. [Railway volumes](https://docs.railway.com/volumes).

## Deployment order, when separately authorized

1. Integrate the approved code and the small deployment changes above. Validate the locked production Composer install and generated image without database access or cached build-time credentials. Do not use `composer setup`; it generates a key and runs migrations. This API directory has no frontend package build.
2. Verify the reported PostgreSQL service/version and the private connection from the intended API environment, without printing connection URLs. First run the migration and feature suite against an isolated disposable PostgreSQL database; current accepted API test evidence is SQLite only.
3. Configure the stable secrets, frontend origin, private rewrite, volume and proxy policy. Arrange database and volume backups before introducing valuable data.
4. Set a pre-deploy command candidate of `php artisan config:clear && php artisan migrate --force --no-interaction`. This is a proposal, not a command executed during this task. Run no seeders. Railway pre-deploy runs after build in a separate container with environment/private networking but no mounted volume; nonzero exit must block deployment. Serialize deployments/migration execution. [Pre-deploy behavior](https://docs.railway.com/deployments/pre-deploy-command).
5. Start the API with the explicit override after successful migrations. Set `/up` as the boot healthcheck, then run the database/session/photo checks below: `/up` alone proves neither database nor storage readiness.
6. Only after verification, use the existing hidden-password operator provisioning command for an explicitly approved staging account. No default user or automatic administrator creation.

Check login/logout, secure host-only cookies and CSRF; separate-user hotel access denial; password reset revoking old sessions in two browser contexts; autosave conflict/validation and Back/Forward recovery; allowed photo upload/read followed by restart and authorized read again; unauthorized photo denial; and bounded login throttling with the observed client-IP policy. Use disposable fixtures and log-only mail. Do not exercise payments, bookings, live invitations or PMS writes.

Rollback means redeploying a previously verified compatible application commit. Do not automatically reverse migrations or roll back to the old public preview. Database/file restoration requires an explicit coordinated recovery decision. A volume-backed API can incur restart/deploy downtime; no zero-downtime or multi-replica availability claim is made here.

## Handoff status

Required from product owner/platform owner: actual API/database service identities, frontend hosting/network location, selected PostgreSQL version, stable frontend HTTPS origin, and approval of the proposed integration slice. No secret values should be supplied in task messages. Hosted connectivity, forwarding-header behavior, volume ownership and PostgreSQL compatibility remain unverified. This document changes no API contract or infrastructure.
