# Catalog and manual inventory contract proposal

Status: core contracts approved by product owner; Stage A1 implementation authorized; remaining Stage A2/B details proposed; 2026-09-23. Component: `catalog`, branch `team/catalog`, inspected baseline `bad09b5bdf9bf071a9c52f727040dd074ab69fd2`. The initial proposal was documentation-only. The Stage A1 update below records the subsequently authorized schema/read implementation and checks. A dedicated PostgreSQL 17.11 dependency and checkout-owned test cluster were subsequently authorized and provisioned; no PMS calls, bookings, payments, or production migrations were performed.

## Evidence and existing constraints

- `apps/api/app/Http/Requests/SaveOnboardingRequest.php`: draft rooms are `{name, occupancy, quantity, rate}` with nullable values, no identity, maximum 50 entries. Rates accept numeric values; currency is absent from storage.
- `apps/web/src/app/admin/onboarding.tsx`: array position is the editor identity; duplication copies fields. UI labels indicative rates as LKR. Photos are associated by caption instructions only.
- `apps/api/app/Http/Controllers/HotelOnboardingController.php`: locks the hotel, checks `onboarding_version`, replaces supplied draft fields, and always returns `can_publish: false`. `inventory_request` is only a preference.
- `apps/api/database/migrations/2026_09_23_091809_create_hotel_photos_table.php` and `HotelPhotoController.php`: private hotel-scoped photo records already exist. Reuse them; do not infer a room association from captions.
- `HotelPolicy.php`, `Hotel.php`, `HotelResource.php`, `routes/web.php`: session/CSRF authentication, `/api/v1`, snake_case fields, integer IDs, hidden cross-hotel resources, action gates and access audit events are established conventions.
- `apps/api/phpunit.xml`: current suite uses SQLite `:memory:` and array mail. This is not concurrency evidence for the proposed transactional database. Future dedicated concurrency configuration must explicitly use log mail as assigned.
- Read root/API/web `AGENTS.md`, `docs/requirements.md`, `tasks/plan.md` and the product owner's `tasks/team.md`. No `.ai/rules` exists in this checkout. Composer lock pins Laravel `v13.33.0`; no runtime dependencies were installed or inspected through Composer.

## Proposed decisions and rationale

Use normal Eloquent models, FormRequests, policies, API Resources and focused transaction services. No generic provider/plugin/event framework. Reuse existing numeric IDs and snake_case JSON. A room type is a sellable category, not a physical numbered room. Room names and draft array indexes are never external identifiers.

Conversion creates unpublished catalog records only. It does not activate ownership, generate nightly stock, publish a listing or produce a valid quote. Catalog completeness and inventory readiness are separate states.

One room type initially has one inventory pool; all its rate plans share that pool. Rates and restrictions are per room/rate offering, but quantities cannot be counted separately for each rate plan. This deliberately narrows the requirements' offering-level ownership: mixed manual/PMS ownership for plans sharing physical stock is rejected initially. Supporting genuinely separate contractual allotments later requires explicit disjoint pools and reconciliation, not duplicated quantities.

## Smallest schema, staged rather than migrated now

Entity records have normal primary keys and timestamps; the room/photo association uses a composite primary key and no unnecessary surrogate identity. IDs are positive JSON integers consistent with existing resources; clients must treat them as opaque. Foreign keys enforce references; nested records must also match hotel ownership, preferably with composite `(hotel_id, id)` keys where duplicated hotel columns occur. Never cascade-delete referenced reservations or inventory history.

| Stage/table | Fields and invariants |
|---|---|
| A: `room_types` | `hotel_id`, `name` (1–255 trimmed chars), `max_occupancy` (1–30), `status` (`draft`, later `active`/`archived`), `version` (positive integer). No stock/rate or provider ID here. Names need not be unique. |
| A: `room_type_photos` | `hotel_id`, `room_type_id`, `hotel_photo_id`, `position` (0–49). Unique room/photo pair and room/position. Both parents must belong to this hotel. A photo may serve multiple room types. Existing caption is alt text; first ordered association is cover. Stage A1 cascades association removal when a photo is deleted, preserving the existing draft-photo deletion workflow. No polymorphic media model. |
| A: `catalog_conversions` | Unique `hotel_id` (one initial conversion), `onboarding_version`, `actor_id`, immutable source snapshot, request hash, resulting ordered `{draft_index, room_type_id}` mapping. Snapshot retains indicative rate/quantity without treating them as catalog facts. |
| B: `rate_plans` | `hotel_id`, `room_type_id`, `name`, `currency` (initially LKR), explicitly reviewed `cancellation_policy`, `status` (`draft`, `active`, `archived`), `version`. No automatic refundable/non-refundable default. First slice prices one room/night for up to `max_occupancy`, with no occupancy bands or derived plans. |
| B: `inventory_pools` | Unique `room_type_id`, `hotel_id`, `owner` (`unconfigured`, `manual`, `pms`), `ownership_version`, `sales_state` (`closed`, `open`). Provider connection/mapping references belong to PMS owner, not hotel-editable input. Unconfigured/closed is the initial state. |
| B: `inventory_nights` | `hotel_id`, `inventory_pool_id`, `stay_date`, `capacity`, `held`, `sold`, `version`; unique pool/date, nonnegative values and `held + sold <= capacity`. Manual allocation to Niwadu, not total hotel physical rooms. Missing row means unavailable. |
| B: `rate_plan_nights` | `hotel_id`, `rate_plan_id`, `stay_date`, `base_minor`, `tax_minor`, `fee_minor`, `mandatory_charges_complete`, `stop_sell`, `version`; unique plan/date. Integer minor units, nonnegative, no binary float money. Missing price is unavailable. Taxes/fees must be explicitly resolved before quote activation. |
| B: inventory reservations | Inventory-owned reservation header (`intent_id`, hotel/pool/plan IDs, `ownership_version`, `state`, `expires_at`, immutable amount/policy snapshot), and unique reservation/date lines (`quantity`, `amount_minor`). States `held`, `committed`, `released`, `expired`; booking links by stable reservation ID. Exact names/FKs coordinated with bookings before migration. |
| B: inventory commands | Unique hotel/operation/idempotency-key, canonical request hash, actor/intent, result status and resource ID. Commit command result and stock changes in the same transaction. This is inventory-specific, not a global workflow framework. |

Hotel timezone is required before dated stock activation; propose an explicit IANA timezone field, selected as `Asia/Colombo` for Sri Lankan hotels, not inferred silently. Dates are local hotel calendar dates; stays use `[check_in, check_out)`. Expiry timestamps use UTC database time. Ownership changes are privileged, audited, reconcile existing obligations, and increment ownership_version; initially allow only explicit `unconfigured -> manual` or `unconfigured -> pms`. Other transitions remain unsupported pending a cutover design.

## Stage A API and explicit conversion

Prefix all routes below with `/api/v1/hotels/{hotel}` and use existing private/authenticated middleware. Unknown writable fields, including ownership, provider configuration, payment fields and status injection, are rejected. Hotel ID comes from the authorized route, never mass-assigned input.

```ts
type Room = {
  id: number; hotel_id: number; name: string; max_occupancy: number;
  status: 'draft' | 'active' | 'archived'; version: number;
  photos: { id: number; caption: string; position: number; url: string }[];
};
type ConvertInput = {
  onboarding_version: number;
  rooms: { draft_index: number; photo_ids: number[] }[];
};
type Conversion = {
  id: number; hotel_id: number; onboarding_version: number;
  rooms: { draft_index: number; room_type_id: number }[];
};
```

| Endpoint | Input/output and retry semantics |
|---|---|
| `GET /room-types?page=1&per_page=25` | No body. Page >=1, per_page 1–50; ID ascending. Laravel paginated Resource response `{data: Room[], links, meta}`. |
| `GET /room-types/{room}` | No body. `{data: Room}`; scoped to route hotel. |
| `POST /catalog-conversion` | `ConvertInput`; 201 `{data: Conversion}` initially, 200 same mapping on matching retry. Unique hotel conversion plus request hash is the durable idempotency boundary; no separate header needed here. |
| `GET /catalog-conversion` | No body. `{data: Conversion}`, or 404 if absent. Supports recovery after an ambiguous response. |

Conversion requires an explicit employee review action. Lock hotel first, then check authorization again and the conversion record. If a record exists, identical canonical input replays its result even if onboarding version later changed; changed input gets 409 and must not create another conversion. If absent, compare current onboarding_version; stale version gets 409. Require every current draft room index exactly once, 1–50 complete room names/occupancies, unique ordered photo IDs per room, and same-hotel existing photos. Empty photo lists are permitted for draft catalog; they do not pass future publication readiness.

Read room name/occupancy from the locked draft snapshot, never trust repeated values from the client. Insert room types, photo links, conversion snapshot and mapping, plus `catalog.converted` access event in one transaction. Return server-generated IDs. Any invalid room/photo aborts the entire conversion. Quantity/rate remain in source evidence only. Conversion may proceed with incomplete indicative rates because pricing is a later explicit operation.

Do not match subsequent drafts by name or array position. PO decision: do not freeze editable drafts without a usable catalog destination. Stage A1 exposes reads only and leaves onboarding/photo editing intact. Stage A2 must ship explicit conversion together with the catalog editor and administration handoff; only then may converted draft-room edits be redirected/guarded. Other onboarding fields continue autosaving. No reconversion/merge endpoint is implied. Stage A1 photo deletion removes associations by foreign-key cascade, with no dangling references; any stronger published-media protection belongs to later publication readiness.

Use existing Laravel error shape `{message, errors?}`; new conflicts additionally carry stable `code` (e.g. `draft_version_conflict`, `catalog_already_converted`, `photo_in_use`). 401 unauthenticated, 403 visible hotel but action forbidden, 404 hotel/nested resource outside scope, 409 state/version conflict, 422 malformed/invalid fields. Existing error contracts remain unchanged. Validate all resource IDs under authorized hotel before mutation; error details must not reveal foreign hotel records.

## Stage B catalog and manual entry API

Proposed only; coordinate implementation after Stage A acceptance. Same prefix and error conventions.

- `POST /room-types`: `{name, max_occupancy, photo_ids}` -> 201 `{data: Room}`. Require durable `Idempotency-Key`; same input replays, changed input 409.
- `PATCH /room-types/{room}`: `{version, name?, max_occupancy?, photo_ids?}` -> 200 `{data: Room}`. Complete ordered photo_ids replaces associations; omitted preserves. Version mismatch 409. Retry is safe from duplicate effects but may return 409; refetch after timeout. Existing commitments retain captured occupancy/policies. Archival requires a separate coordinated readiness check; no hard-delete API.
- `POST /room-types/{room}/rate-plans`: `{name, currency:'LKR', cancellation_policy}` -> 201 `{data: RatePlan}` with IDs, draft status, version; same key semantics as room creation. `GET` same collection supports page/per_page and returns paginated RatePlans. `PATCH .../{plan}` takes `{version, name?, cancellation_policy?}`, returns updated RatePlan. Currency immutable once priced; status not client writable.
- `PUT /room-types/{room}/inventory-nights/{date}`: `{version, capacity}` -> `{data:{date,capacity,held,sold,available,version}}`; missing row uses version 0. Rate edit: `PUT /room-types/{room}/rate-plans/{plan}/nights/{date}` with `{version, amount_minor, stop_sell}` -> `{data:{date,amount_minor,currency,stop_sell,version}}`. Versions are mandatory; stock counters and ownership never writable. Require bounded valid dates, amount limits, and pool owner manual. Stale version or capacity below obligations returns 409 without partial effects.
- `GET /room-types/{room}/inventory-nights?from=YYYY-MM-DD&to=YYYY-MM-DD` returns `{data: Night[]}` for explicit half-open range of at most 366 days, including unavailable/missing dates with `configured:false` (not fabricated prices/capacity). `GET .../rate-plans/{plan}/nights` uses identical range with `{data: RateNight[]}`; configured entries match PUT response, missing entries contain only date/configured:false. No unbounded calendar reads.

Permission matrix: administrator and employee-owned draft can convert and edit draft room content/photos; hotel_manager can edit room content in their hotel after conversion, but cannot invoke employee conversion. inventory_manager may edit manual rates/stock but not room content; hotel_manager and administrator may edit manual rates/stock. Onboarding employees do not activate ownership or enter sellable inventory by default. Reservations/viewer are read-only for this component. Every member-visible read still checks hotel scope; every write checks the specific action and fresh membership. Only authorized platform staff can activate/switch ownership or configure mappings. No membership role grants this privilege.

## Manual inventory transaction and booking boundary

Catalog owns stable room/plan/pool IDs, dated prices and inventory commands. Bookings owns customer quotes, guest/booking state, payment decisions and reservation orchestration. PMS owns provider connections, external IDs, capability/freshness checks and provider-side reservation operations. Mapping is keyed by Niwadu IDs; external room/rate IDs never replace them.

Approved sellable selection identity: `rate_plan_id`, resolving hotel + room + plan; no offering table or alias. Proposed internal input: `{hotel_id, rate_plan_id, ownership_version, check_in, check_out, quantity: 1, adults, children: 0, intent_id, idempotency_key}`; reject unsupported child pricing explicitly and validate adults against room occupancy. Do not trust a client-supplied room identity independently of the rate plan. `inspect` returns date lines, currency, policy/price versions, total_minor, and `available_as_of`; it is not a stock guarantee. `hold` additionally takes the expected quote fingerprint and returns `{reservation_id, state, expires_at, lines, total_minor, currency}` or an explicit conflict. `commit`, `release`, and `expire` take reservation ID and stable command key; commit also carries booking intent/reference. A room-level inventory reservation must never be consumed by two different booking intents.

Approved first booking slice: one room type/rate plan per intent, quantity exactly 1, stay 1–30 nights, LKR integer minor units, adults only; unsupported child pricing is rejected. Inventory owns hold records; bookings owns intent. Development hold TTL is configurable, default 15 minutes, capped by provider expiry when applicable; this is not a production provider guarantee. PO subsequently requires explicit per-night integer base_minor, tax_minor, fee_minor and mandatory_charges_complete; unknown components are not zero. Totals are derived. Unsupported or unallocated stay-level fees block quotes. Any changed rate/policy/ownership fingerprint requires requoting, not silently charging a new price.

Transaction algorithm (all stock writers use the same order):

1. Atomically claim operation key with a unique insert, then lock the pool. For multi-pool support later, lock ascending pool IDs. This deliberately serializes writes within a room pool; optimize only if measured contention demands it.
2. Verify owner/manual, ownership_version, sales readiness and hotel/plan relationships. Lock reservation header if applicable, then affected night rows in date order and rate rows in date order. All catalog/rate readiness changes must observe the same pool lock protocol. Missing nights fail closed; hold never creates stock rows.
3. For hold: verify all night prices/restrictions, occupancy and `capacity - held - sold >= quantity`. Insert reservation and lines, increment held on every night, store immutable quote snapshot and command result atomically. Any night failure rolls back everything. Plan variants consume the same pool counters.
4. Commit only a held, unexpired reservation: decrement held and increment sold for every night in the same transaction. Release/expire held stock exactly once; racing commit and expiry serialize on the pool/header. Use database time sampled after acquiring locks for expiry decisions. Expiry sweep delay conservatively reduces availability until released; never ignore an expired header while retaining its held counters.
5. Cancellation after commit is a distinct booking-authorized operation: decrement sold once on every reserved night; repeating cannot create capacity. Terminal state transitions cannot be reversed by retries. Payment success cannot revive an expired/released reservation; bookings must reconcile or compensate.

Same idempotency key/different canonical payload -> 409. A concurrent duplicate waits only for a bounded database lock timeout; completed duplicates replay the persisted result, otherwise return 409 `operation_in_progress` for same-key retry. Claim and result are in one local transaction, so a crash rolls back both or commits both; lookup after network timeout resolves uncertainty. Never expire/delete these keys in the initial release; retention policy must later exceed all retry/replay windows. Auth/scope checks precede replay. Deadlock retries rerun the full local transaction with the same key and no external effects.

PMS stock is a separate projection, never writable through manual inventory endpoints. Search projections are not reservations. Provider hold unsupported/stale/unknown must stay explicit; never fall back to manual. A stop-sell/OTA cap is a separate reduction, not a provider availability write. Provider network calls must not occur while local inventory row locks are held. PMS and booking owners need their own durable pending/unknown reconciliation contract.

## Transactional database proposal

PO approved a dedicated PostgreSQL 17 instance for Niwadu development/concurrency tests, and the same major engine for eventual deployment unless product-owner infrastructure review chooses otherwise. Pin a supported patch at provisioning. Never point these tests at the PMS instance, existing user data, or a production endpoint. Use database `niwadu_catalog_test_<worker>` and a role limited to those disposable test databases; provisioning is a later coordinated task.

Use READ COMMITTED with explicit row locks, unique constraints and checks. PostgreSQL documents that conflicting row operations wait until the transaction ends and that consistent lock order reduces deadlock risk ([PostgreSQL 17 locking](https://www.postgresql.org/docs/17/explicit-locking.html)). Laravel exposes pessimistic locking for transaction-scoped queries ([Laravel 13 query builder](https://laravel.com/framework/docs/13.x/queries)). The selected pool-first protocol is our design, not a claim that transactions alone prevent overselling.

MySQL/InnoDB is a viable alternative if deployment constraints require it, but choosing two engines doubles concurrency verification. SQLite remains useful for fast existing tests; do not treat SQLite success as lock/isolation proof. Initial proposal installed nothing; see the later PostgreSQL verification addendum for authorized local provisioning.

Concurrency suite must use independent committed connections/processes against the same disposable PostgreSQL database, with deterministic barriers, not sleeps or tests wrapped in one outer rollback transaction. Fail loudly if driver is SQLite, DB_URL is unexpected, database name is not the test prefix, or mail is not log. Use a separate config so existing phpunit SQLite settings cannot accidentally override it. No real invitations or integration/network writes.

## Ordered implementation and acceptance

**A2 — Reviewed conversion plus a usable editor (proposal, after Stage A1).** Implement conversion and its record, scoped create/update endpoints and employee editor together. Do not enable conversion or guard draft-room edits before that editor is usable. Likely files: `apps/api/routes/web.php`, `app/Models/RoomType.php`, `app/Models/CatalogConversion.php`, `app/Http/Requests/ConvertCatalogRequest.php`, `app/Http/Resources/RoomTypeResource.php`, `app/Http/Controllers/RoomTypeController.php`, `app/Http/Controllers/CatalogConversionController.php`, `app/Http/Controllers/HotelPhotoController.php`, `app/Http/Controllers/HotelOnboardingController.php`, a focused conversion service, migrations/factories and `tests/Feature/RoomCatalogTest.php`. All listed paths after `apps/api/routes/web.php` are relative to `apps/api`. Existing hotel permissions should be extended narrowly, not rewritten. Administration owns the coordinated UI work; API conversion activation and UI availability must be accepted together.

Acceptance: (1) owned-draft employee explicitly converts two rooms and receives stable IDs, (2) same input replay returns identical IDs, concurrent conversions create exactly one mapping, (3) stale version/invalid occupancy/foreign photo rolls back all rows, (4) every nested ID/read/write is hotel scoped, revoked members fail and prohibited roles/fields fail, (5) photo ordering persists, captions are never parsed and photo deletion leaves no dangling associations, (6) draft quantity/rate creates zero stock/rate rows and publication remains blocked, (7) later draft room edits cannot overwrite catalog or create duplicates. Test same-name rooms and reordered indexes at stale version. Focused PHPUnit checks plus existing onboarding/photo regression suites; PostgreSQL race check once provisioned, not an SQLite concurrency claim.

**B — Manual inventory behind closed sales.** Add room editing, rate plans, explicit platform ownership activation, nightly input and local hold/commit/release operations only after contracts are accepted. Proposed files: relevant models/migrations/factories, `ManualInventoryService`, FormRequests/Resources/controllers, hotel action policies, and `tests/Feature/ManualInventoryTest.php` plus a dedicated PostgreSQL concurrency suite/config. No provider abstraction beyond agreed boundaries.

Acceptance: two concurrent requests for the final unit yield exactly one hold; two rate plans cannot sell the same remaining unit; a multi-night failure alters no night; duplicate hold/commit/release/cancel changes counters once; changed key payload conflicts; capacity reduction races safely with holds; expiry versus commit yields one consistent terminal state; timeout-after-commit replays; missing rate/night fails closed; manual writes to PMS/unconfigured pools fail; stale ownership versions fail; all mutations audit actor/intent and hotel; foreign IDs and revoked staff cannot read or mutate. Assert persisted invariants using a fresh connection after both workers finish. No quote activation until taxes, TTL, price acceptance and booking state contracts are agreed.

## Product-owner decisions and remaining coordination

Approved: PostgreSQL 17; integer room/plan IDs; one pool per room type with no mixed ownership across plans; `rate_plan_id` as selection identity; inventory-owned holds / booking-owned intent; quantity 1, 1–30 nights, adults only, LKR integer minor units; configurable 15-minute development TTL capped by provider expiry. Catalog exclusively owns catalog migrations and proposes isolated database provisioning/configuration. No service install or hosted cost is authorized yet.

Remaining: lossless stay-level fee allocation (unsupported fees block quoting); PMS mapping cardinality/capability/freshness/unknown outcomes; coordinated conversion/editor activation. Catalog will send API/schema references directly to bookings and PMS using task IDs from the central board, as requested by PO.

## Stage A1 delivery and Stage A2 proposal

A1 implements only room_types and room_type_photos schema, factories, RoomType model/resource/controller and authenticated GET list/show routes. No rate plan, pool, conversion or hold table is created early. Reads return `{id,hotel_id,name,max_occupancy,status,version,photos:[{id,caption,position,url}]}`; photo URLs are relative same-origin private API paths. List uses page/per_page (default 25, maximum 50), ID order, and Laravel data/links/meta. Show scopes room lookup by authorized hotel even for administrators. Existing membership/employee policy is reused; revoked memberships are checked on each request.

Composite foreign keys enforce that both room and photo match association hotel_id; pair/position uniqueness prevents duplicate links or cover ordering ties. The pivot has no surrogate ID/timestamps. Room deletion is not exposed. Hotel deletion is restricted when catalog rooms exist. Existing draft photo deletion cascades only its links; neither room content nor file paths are exposed by this read API. Occupancy bounds and field editing validation belong to A2's write boundary; A1 has no room writes.

Local capability inventory (read-only, 2026-09-23): Homebrew libpq supplies `psql 18.6`; PHP 8.4 includes pdo_pgsql and pgsql. No postgres executable, PostgreSQL Homebrew formula/service, docker or podman binary was found on PATH or checked standard Homebrew/local paths. /Applications contains no Docker app. Homebrew reports existing MariaDB and Redis services; these were not connected to or modified. This is a bounded local inventory, not proof no other server exists elsewhere.

Provisioning proposal: a later explicitly authorized PostgreSQL 17 install, a catalog-owned cluster under this checkout's ignored runtime directory, localhost-only port 55434 after checking availability, and separate `niwadu_catalog_dev` / `niwadu_catalog_test_<worker>` databases. Choose a dedicated service account/database role; never use PMS credentials. Use per-checkout runtime paths and stop only this owned server. Add a dedicated PostgreSQL PHPUnit config and a bootstrap preflight validating pgsql driver, localhost/assigned port, blank DB_URL, `niwadu_catalog_test_` database prefix, APP_ENV=testing and MAIL_MAILER=log before any reset/migration; schema-only role must not access other databases. Committed config contains placeholders only. No implementation/install/start was performed for this proposal.

A2 coordination: catalog owns conversion snapshot/id mapping, transactional create/update APIs with version checks, and same-hotel photo validation; hotel administration owns a usable room editor and explicit conversion review UI. First ship/test that destination with catalog API, then enable conversion and redirect/guard converted draft-room edits in the same accepted release. Match draft version and explicit photo choices; never infer identity from captions or names. Keep quantities/rates as evidence only and publication blocked. Acceptance includes employee save/resume/edit after conversion, conflict recovery, cross-hotel/revoked access, duplicate conversion, photo delete/reorder and no freeze without a destination. Dates/rates/stock remain a subsequent slice.

Verification results and local commit references are recorded after final checks below.

Stage A1 checks: focused red run failed on missing routes/schema, then 9 catalog tests / 73 assertions passed. Full API suite passed: 35 tests / 222 assertions with SQLite in-memory, explicit testing-only APP_KEY, blank DB_URL and MAIL_MAILER=log. Pint passed. Fresh disposable SQLite file migration, rollback of the two catalog migrations, and re-migration all passed; route:list shows only two GET|HEAD catalog routes. PostgreSQL migration/concurrency checks remain pending provisioning; SQLite results are not evidence of PostgreSQL locking. No frontend changes or browser run. Review found no unresolved Stage A1 correctness/isolation findings; independent QA acceptance remains with the product owner.


## PostgreSQL 17 verification addendum — 2026-09-23

PO authorized dedicated local provisioning after Stage A1; this does not grant integration/merge acceptance. Installed **PostgreSQL 17.11 (Homebrew), server_version_num 170011** with `brew install --skip-post-install postgresql@17` and automatic update/cleanup disabled. Default Homebrew PGDATA `/opt/homebrew/var/postgresql@17` was confirmed absent. Skipping post-install also omitted required support links; added only `/opt/homebrew/share/postgresql@17 -> /opt/homebrew/opt/postgresql@17/share/postgresql` and `/opt/homebrew/lib/postgresql@17 -> /opt/homebrew/opt/postgresql@17/lib/postgresql`. No Homebrew service registration/start or hosted service.

Cluster: `/Users/rashmiassiriyage/niwadu-worktrees/catalog/.runtime/pgsql/data`; socket in sibling `socket`, log `server.log`. Runtime directory is gitignored, directory permissions 0700 and credential file `test.env` permissions 0600. Port 55434 was verified free by a successful local bind before initialization. Cluster name is `niwadu_catalog_isolated`; observed listener was only `127.0.0.1:55434`. Existing MariaDB/Redis services were untouched; Homebrew showed postgresql@17 service `none`.

Authentication: local peer access only for the checkout owner; TCP scram-sha-256 only for `niwadu_catalog_test` to `niwadu_catalog_test_a1` on 127.0.0.1; all other TCP/local clients rejected. Test role has LOGIN and owns only its disposable test database; NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOREPLICATION, NOBYPASSRLS. PUBLIC database access revoked for test DB, postgres and template1. No production/PMS credentials were read. Additional workers require explicit database provisioning and HBA entries, not wildcard database permission.

`apps/api/phpunit.pgsql.xml` selects migration/catalog/access/photo/onboarding tests and `tests/postgres-bootstrap.php`. The bootstrap rejects unsafe environment before connection (driver, address, port, username, DB_URL, database prefix, environment/mail/cache/queue/session), missing password and cached application configuration. It verifies actual database/user, PostgreSQL major 17, isolated cluster name, server address/port and absence of elevated role flags before any migrations. Credentials are supplied from the ignored runtime file; none are committed. Initial guard rejected PostgreSQL's inet text mask `/32`; using `host(inet_server_addr())` now compares the exact host correctly.

Stage A1 PostgreSQL verification passed **22 tests / 171 assertions**, including new migration up/down/up test retaining an existing hotel photo, both composite cross-hotel FK rejections, unique position enforcement, shared-photo behavior, read scoping/revocation, onboarding editability and private photos. The test runner uses the restricted role, not the cluster administrator. Unsafe DB_HOST, DB_DATABASE, DB_CONNECTION, DB_URL and MAIL_MAILER inputs each failed bootstrap before connecting. This proves schema and isolation behavior on PostgreSQL; stock concurrency remains unimplemented and unclaimed.

Reproduction commands (from the catalog checkout; server lifecycle may require normal sandbox approval):

```sh
/usr/sbin/lsof -nP -iTCP:55434 -sTCP:LISTEN
# Confirm port free before starting this stopped, checkout-owned cluster.
/opt/homebrew/opt/postgresql@17/bin/pg_ctl -D /Users/rashmiassiriyage/niwadu-worktrees/catalog/.runtime/pgsql/data -l /Users/rashmiassiriyage/niwadu-worktrees/catalog/.runtime/pgsql/server.log -w start
source /Users/rashmiassiriyage/niwadu-worktrees/catalog/.runtime/pgsql/test.env
# This fixed key is testing-only, not a production credential.
APP_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa /opt/homebrew/opt/php@8.4/bin/php /Users/rashmiassiriyage/niwadu-worktrees/catalog/apps/api/vendor/bin/phpunit -c /Users/rashmiassiriyage/niwadu-worktrees/catalog/apps/api/phpunit.pgsql.xml
/opt/homebrew/opt/postgresql@17/bin/pg_ctl -D /Users/rashmiassiriyage/niwadu-worktrees/catalog/.runtime/pgsql/data -m fast -w stop
```

Stage B price coordination: bookings acknowledged `{stay_date,base_minor,tax_minor,fee_minor,mandatory_charges_complete:true}` per accepted nightly quote line. Its synthetic e0c0cbb calculator currently uses `date` and a top-level completeness flag, so direct shape alignment is a future bounded task, not a working binding. Derive nightly/stay totals from components; do not persist redundant independent totals. Unknown values/incomplete charges block quote acceptance. Any stay-level fee needs an explicit lossless allocation rule first. No stock/hold or price migrations were added.

Future reference review read: `/Users/rashmiassiriyage/niwadu-v2/docs/feature-reference-review.md`. No conflict with current room identity/read contracts. Classification should keep property type, location, themes and sourced stars distinct; date/guest price filters require real dated inventory, and planner unknown costs must stay unknown. No archive code, Prisma schema, map, planner or taxonomy implementation was imported.

Final checks: test-role CONNECT privilege is false for postgres and true for its own disposable database. The destructive migration test re-runs the preflight even when invoked directly, and asserts the resolved Laravel database matches the guarded database. Invoking it under the ordinary SQLite config fails before migration. Pint passed; the normal SQLite suite remains 35 tests / 222 assertions. The checkout-owned server was stopped after verification; installed versioned binaries and ignored PGDATA remain for repeatable local runs.
