# Private API staging candidate on Railway

Local implementation candidate, 2026-09-23. The user confirms an empty `niwadu-api` service, database service `Postgres`, and frontend `https://niwadu-v2-production.up.railway.app`. The user saved `DB_CONNECTION=pgsql` and the `DB_URL` reference on the API. No source is attached yet. This task performed no hosted configuration, database connection, migration or deployment; PostgreSQL version/connectivity remain unverified.

Use the approved administration code at `07309ccb9e665522c6c24b18c5d89ad753a14547` (code equivalent to `61b0913`), or a coordinator integration commit containing those fixes. Public preview commit `e65d817` contains an older administration baseline and is **not** the approved private-backend deployment candidate.

## Service layout and variables

Use separate frontend and Laravel services in the same dedicated staging environment. Set the API service root to `/apps/api`, Railpack builder, one replica and port `8080`. The frontend keeps its existing same-origin `/api/v1/*` rewrite; the API upstream is private Railway networking. Do not expose a separate public API domain for this candidate. This topology must first be checked against the actual frontend hosting/network arrangement. [Railway monorepos](https://docs.railway.com/guides/deploying-a-monorepo) and [private service references](https://docs.railway.com/networking/domains/working-with-domains).

Candidate API variables below contain placeholders, not credentials. Replace `Postgres` with the actual database service alias. `APP_URL` and `FRONTEND_URL` are the same externally visible frontend HTTPS origin, with no trailing slash; the API is served under that origin's `/api/v1` path.

```dotenv
APP_NAME=Niwadu
APP_ENV=production
APP_DEBUG=false
APP_URL=https://niwadu-v2-production.up.railway.app
FRONTEND_URL=https://niwadu-v2-production.up.railway.app
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

On the frontend service, the candidate is `API_ORIGIN=http://${{niwadu-api.RAILWAY_PRIVATE_DOMAIN}}:8080`. It is a server-only variable, with no `NEXT_PUBLIC_` prefix. Rebuild/redeploy the frontend when changing its rewrite target. The frontend must actually run in a network that can reach this private domain; an externally hosted public preview cannot use this value.

Log mail is deliberately limited to disposable staging accounts. The log transport writes reset/setup links, including usable tokens, at debug severity; `LOG_LEVEL=info` would silently hide those messages. With the above configuration, access to Railway logs must be restricted to approved operators and retention kept bounded. Never paste mail payloads into tasks or artifacts. No SMTP credentials, live invitations or production identities are part of this preparation. Once real users are proposed, revisit delivery and token-bearing log handling before enabling them. The present notification flow is synchronous, so this slice needs no queue worker or scheduler.

## Implemented deployment files for coordinator integration

All files below are under `apps/api`. They remain local candidates and have not been deployed:

- `composer.json` now requires PHP `^8.4`, `ext-gd` and `ext-pdo_pgsql`; Composer regenerated lock metadata with no package changes.
- `railpack.json` selects the PHP provider, omits development dependencies, and prepares directories/autoloading without booting Laravel or caching build-time secrets. This API has no Node package/build step.
- `start-container.sh` requires an application key, PostgreSQL configuration and Railway's expected volume mount metadata plus a writable private photo directory. It clears only configuration cache, discovers production packages, rebuilds configuration/event/route caches, and starts FrankenPHP. It never migrates, seeds, generates keys, creates public storage links or clears shared application cache. Volume metadata/directory checks do not independently prove physical persistence; restart verification remains required.
- `pre-deploy.sh` rejects missing PostgreSQL settings rather than falling back to SQLite, then clears configuration cache and runs forced noninteractive migrations. It requires no photo volume, which Railway does not attach to pre-deploy containers.
- `railway.json` sets Railpack, `sh pre-deploy.sh`, `/start-container.sh` and `/up` healthchecking. Set service root `/apps/api` **and explicitly set Railway Config File `/apps/api/railway.json`**; config-file selection does not inherit the service root. Attaching this configuration to a deployment authorizes that deployment's migration step; no migration has run in this task. [Railway build configuration](https://docs.railway.com/builds/build-configuration) and [configuration reference](https://docs.railway.com/config-as-code/reference).
- `php.ini` sets 5M upload/8M POST limits, disables displayed errors and exception argument disclosure, and bounds memory to 256M. Application photo limits and authorization remain intact. Verify near-limit uploads through the proxy chain.

No proxy trust change is included: observed trusted source addresses and header sanitization are still missing. Keep the existing rejection of untrusted forwarding headers. Private HTTP upstream scheme handling and accurate client-IP throttling require a separately verified proxy configuration before admitting users. Do not set a global wildcard trust as a workaround.

A clean production install found that `view:cache` fails because this API has no application views directory, so startup deliberately omits it. Runtime framework mail rendering remains available. Railpack/Docker are not installed locally; generated image execution remains a platform verification step, not a completed local check.

Railpack supports custom startup and PHP configuration files. Its documentation describes migration/seeding behavior, but the inspected startup source currently calls only `migrate --force`, then `storage:link`, `optimize:clear` and `optimize`. Disable startup migration behavior explicitly and use the override to avoid the other unwanted effects. Recheck generated image behavior when implementing; upstream `main` can change. [Railpack PHP](https://railpack.com/languages/php/), [startup source](https://raw.githubusercontent.com/railwayapp/railpack/main/core/providers/php/start-container.sh), [provider source](https://raw.githubusercontent.com/railwayapp/railpack/main/core/providers/php/php.go).

The proxy acceptance test must check external scheme, host and client address through Railway → Next → Laravel, including spoofed incoming forwarding headers. Railway documents `X-Forwarded-Proto: https`; that alone does not establish sanitization across the Next hop. Existing login limits use `$request->ip()`. Until the client-IP contract is verified, they may bucket all users by the frontend address; do not remove or relax them to compensate. Retain a closed staging audience until the proxy behavior is settled. [Railway proxy headers](https://docs.railway.com/networking/public-networking/specs-and-limits).

## Persistent photos

Attach a dedicated API volume at `/app/storage/app/private`. With `/apps/api` as the service root, Railpack places the application at `/app`. `HotelPhotoController` explicitly reads, writes and deletes on the `local` disk rooted at `storage/app/private`; changing `FILESYSTEM_DISK` or adding S3 variables alone will not move photos. Do not expose this directory through a public symlink or mount over the entire application/storage tree.

Confirm the runtime user can write the mounted directory and reject startup when the expected mount is missing. Volumes are attached at runtime, not during build or pre-deploy; ownership may need explicit operator configuration. Keep this initial API to one replica. Back up this volume separately from PostgreSQL and coordinate restoration of photo records and files. [Railway volumes](https://docs.railway.com/volumes).

## Deployment order, when separately authorized

1. Integrate the approved code and the small deployment changes above. Validate the locked production Composer install and generated image without database access or cached build-time credentials. Do not use `composer setup`; it generates a key and runs migrations. This API directory has no frontend package build.
2. Verify the reported PostgreSQL service/version and the private connection from the intended API environment, without printing connection URLs. First run the migration and feature suite against an isolated disposable PostgreSQL database; current accepted API test evidence is SQLite only.
3. Configure the stable secrets, frontend origin, private rewrite, volume and proxy policy. Arrange database and volume backups before introducing valuable data.
4. The committed Railway configuration selects `sh pre-deploy.sh`, which checks required configuration before `config:clear` and `migrate --force --no-interaction`. This has not been executed against a real database during this task. Run no seeders. Railway pre-deploy runs after build in a separate container with environment/private networking but no mounted volume; nonzero exit must block deployment. Serialize deployments/migration execution. [Pre-deploy behavior](https://docs.railway.com/deployments/pre-deploy-command).
5. Start the API with the explicit override after successful migrations. Set `/up` as the boot healthcheck, then run the database/session/photo checks below: `/up` alone proves neither database nor storage readiness.
6. Only after verification, use the existing hidden-password operator provisioning command for an explicitly approved staging account. No default user or automatic administrator creation.

Check login/logout, secure host-only cookies and CSRF; separate-user hotel access denial; password reset revoking old sessions in two browser contexts; autosave conflict/validation and Back/Forward recovery; allowed photo upload/read followed by restart and authorized read again; unauthorized photo denial; and bounded login throttling with the observed client-IP policy. Use disposable fixtures and log-only mail. Do not exercise payments, bookings, live invitations or PMS writes.

Rollback means redeploying a previously verified compatible application commit. Do not automatically reverse migrations or roll back to the old public preview. Database/file restoration requires an explicit coordinated recovery decision. A volume-backed API can incur restart/deploy downtime; no zero-downtime or multi-replica availability claim is made here.

## Handoff status

Service identities and frontend origin are confirmed above. Still required: selected PostgreSQL version, actual private connectivity/forwarding observations, volume setup and approval to integrate/deploy the implementation candidate. No secret values should be supplied in task messages. Hosted connectivity, forwarding-header behavior, volume ownership and PostgreSQL compatibility remain unverified. The candidate changes no API contract, proxy trust policy or hosted infrastructure.
