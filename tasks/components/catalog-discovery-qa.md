# Discovery API fixture verification

Metadata-only release, immutable reserved slug, version-fenced admin actions, snapshot-only public reads. No stock, rate plan, price, hold or booking behavior. Public routes are `/api/v1/public/hotels`, `/api/v1/public/hotels/{slug}`, `/api/v1/public/discovery-options`. List requires `sort=name`; supported absent property type returns 200 empty, unknown type 422. Only name substring and property_types[] filters are supported. Public photo is null. All responses use no-store.

Verified locally: SQLite full suite 41 tests / 357 assertions; PostgreSQL 17 suite 29 tests / 328 assertions, including row-lock barrier tests for competing releases and delayed release after withdrawal, migration rollback/reapply, and existing isolation regression tests. Synthetic seeder ran successfully. QA should test the committed candidate, with public frontend d261660c4a1fd2c3cb2d2d1ee14c429e2729be54.

## Isolated local fixture setup

Catalog-owned PostgreSQL cluster is currently running at 127.0.0.1:55434. Coordinate before resets: tests reset the dedicated database. Never run tests while using its interactive fixture server. No hosted environment or real property is involved.

From this candidate's `apps/api` directory (PHP dependencies already installed in catalog checkout):

```sh
source /Users/rashmiassiriyage/niwadu-worktrees/catalog/.runtime/pgsql/test.env
export APP_ENV=testing APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55434
export DB_USERNAME=niwadu_catalog_test DB_DATABASE=niwadu_catalog_test_a1 DB_URL=
export MAIL_MAILER=log QUEUE_CONNECTION=sync CACHE_STORE=array SESSION_DRIVER=array
# Test runner preflights server identity and role before resetting only this disposable DB.
/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit -c phpunit.pgsql.xml
# Fixture password below is synthetic and only valid on this isolated testing database.
export DISCOVERY_FIXTURE_PASSWORD=local-synthetic-only-password
/opt/homebrew/opt/php@8.4/bin/php artisan db:seed --class=DiscoveryTestSeeder --force
/opt/homebrew/opt/php@8.4/bin/php artisan serve --host=127.0.0.1 --port=8204
```

Seeder independently repeats the PostgreSQL identity guard. Creates synthetic villa/hotel releases at `qa-fixture-villa` and `qa-fixture-hotel`; administrator `discovery-admin@example.test`. Reruns update these synthetic records. Other test-created data can remain; filter `q=Synthetic` for these two. Hostel remains a valid absent type. Configure frontend API origin to http://127.0.0.1:8204 using its documented environment variable.

Review `GET /api/v1/hotels/{id}/discovery-review`, release `PUT /api/v1/hotels/{id}/discovery` with `{onboarding_version,discovery_version,slug}`, withdraw `DELETE` same path with `{discovery_version}`. Use administrator authentication. A review exposes proposed metadata/validation errors/current snapshot; release copies only validated allowlisted metadata. Stale version or unavailable/reserved slug returns 409. Hotel status remains draft, can_publish unchanged. No public media URL is created.

## Next manual inventory candidate

Use the same guarded test environment above with the new candidate. Run its PostgreSQL suite first (schema differs from discovery-only releases), then `php artisan db:seed --class=ManualCatalogTestSeeder --force` using the PHP8.4 binary above. It repeats the discovery guard, seeds only synthetic room/plan/stock and prints actual hotel_id/rate_plan_id/arrival/departure/adults. Dates begin seven days after seeding, last two nights, explicit checkout restriction included. Public slug `qa-fixture-villa`; base1,000,000+tax100,000+fee0 minor units per night, two-night total2,200,000 LKR minorunits. No obligations are reset; fixture aborts if held/sold units exist. Do not run tests concurrently with any other owner of the shared dedicated DB. Manual quote source wiring into bookings and admin/public UIs remain separately gated integrations.
