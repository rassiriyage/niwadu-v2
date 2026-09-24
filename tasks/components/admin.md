# Administration component — operator provisioning and recovery fixes

Status: combined candidate verified locally and independently accepted by QA; D-01/D-02 design accepted from source/screenshots. Railway deployment files are implemented locally and verified; hosted runtime remains unverified. Nothing pushed, merged or deployed.
Branch: `team/admin`, based on `bad09b5`.

| Slice | Exact commit |
| --- | --- |
| Operator provisioning | `898eac6edcf4a373020be22b8ff2c1f6b62b58db` |
| D-01/D-02 implementation checkpoint (now verified by combined checks below) | `eb4d584` |
| QA-01 session revocation | `870ad655d7aabf7487fc343cb331676d84df3348` |
| QA-03 explicit discard/sign-out | `0f6fa0c5a84c97feb1fe1c81175ed6eb9d58935a` |
| QA-02 browser-history recovery / final implementation candidate | `61b09139a6c9484c641b71ea5ac9727f1fb18ec8` |

## Delivered behavior

- Added `niwadu:create-onboarding-employee {email} {--name=}` with the existing administrator command's hidden password, email normalization, name/email/password validation and password hashing behavior.
- Hard-coded the existing `onboarding` role. No role option, public registration, default credentials, hotel membership or email delivery.
- Existing accounts (hotel user, employee or administrator) are rejected without changes. The administrator command is unchanged.
- Root README documents operator setup, secure credential handoff and employee draft scope. No schema/API contract changed.

## Evidence

- New tests failed first because the command did not exist.
- Focused suite: 10 tests / 92 assertions passed, covering validation, normalization, hashing, no mail/notification, existing-account preservation, own-draft create/save/resume, cross-hotel and published-hotel denial, and rejection of administrator/payment/PMS injection.
- Full API suite: 36 tests / 241 assertions passed using PHP 8.4 and `composer test` with `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= MAIL_MAILER=log`.
- `vendor/bin/pint --dirty --format agent` passed. `git diff --check` passed.
- Composer installed locked dependencies inside this checkout (Laravel 13.33.0). A fresh local key was generated from `.env.example`; no secrets were copied or committed.
- No browser/UI changes or browser port use. No live invitations, bookings, payments or PMS writes.

## Boundaries and dependency requests

Payment/PMS configuration endpoints do not exist yet: coverage proves current profile/onboarding field rejection and no administrator role, not future integration authorization. QA should independently review this commit together with inherited access/onboarding rules. Product owner should forward later QA/design findings before dispatching the next bounded increment. No shared schema decision or external account is required for this slice.

## QA-01 password-reset session revocation

- Enabled Laravel 13.33 `auth.session` across the versioned API, including `/session`. This installed framework already seeds `password_hash_web` in `SessionGuard::login`; no custom session schema or deletion loop is needed. Password reset continues rotating the remember token.
- API guest redirects return no redirect target, avoiding an undefined web login route when Laravel rejects a stale hash. Workspace opens sign-in after a 401 during session introspection.
- Five focused feature tests cover immediate login-then-reset revocation at both `/session` and `/hotels`, new versus old password, reset-link reuse denial, unaffected unrelated users, obsolete remember cookies and untrusted remember input. Normal login does not support remember-me.
- Red evidence: two tests returned 200 before middleware; green: full 41 API tests / 277 assertions passed; Pint passed. QA's independent browser reproduction was adapted to accept the first stale request as 401 and then guest state; it passed with database sessions, separate browser contexts, log-only mail and ports 3203/8203. New password succeeds, old password and reused link fail, unrelated employee remains authenticated.
- Existing sessions created by this baseline have the login fingerprint. Any future authentication path must continue using Laravel's session guard login mechanism. No live accounts or PMS databases were used.

## QA-03 explicit sign-out recovery

- If draft flushing fails, a native modal offers Keep editing (initial keyboard focus) or explicit Discard unsaved changes and sign out. Escape keeps editing. The discard action calls backend logout without repeating the failed save; a401 means the session is already gone. Failed logout keeps the decision visible with an error.
- Regression first failed because no decision existed. Chromium checks passed for422 validation,409 conflict and503 server failures, including keep-editing value preservation, explicit discard, guest session and401 on private hotel data. Type checking and lint passed. No P2 layout redesign included.

## QA-02 browser-history recovery

- Browser Back/Forward and reopening recover the local form snapshot, pending field keys, step and original server version from user/hotel-scoped `sessionStorage`. This contains hotel form values only, never passwords/session tokens. Recovery begins only after the authenticated API authorizes the hotel read; it never grants access.
- Recovered changes are labelled unsaved. A newer server version immediately offers the D-01 conflict recovery actions rather than silently merging or submitting. Successful save, explicit reload/discard, logout and observed guest/expired session clear the relevant recovery copy. Async completions from an unmounted editor cannot erase a newer editor's recovery copy. No history monkeypatching or navigation interception dependency was added.
- Local red: Back then Forward returned an empty city. Green: five Chromium cases passed, including failed city save → Back → Forward, another editor update → hard reopen → explicit conflict/reload → resume, room array changes before the debounce → Back/Forward, and clearing recovery after sign-out both inside and outside the editor. Sign-out422/409/503 cases also re-login to prove discarded changes do not return.
- Expanded browser tests use distinct accounts inside the existing guarded SQLite test seeder. Production login limits are unchanged. Type/lint and Pint passed.
- Tab storage is a recovery aid, not a server save or cross-device backup. If browser storage is unavailable/quota-exhausted, the editor warns that no recovery copy could be kept and to keep the tab open/copy values. Photos/staff already use separate server operations; unsent file selections are not retained.

## D-01/D-02 completed behavior and combined verification

- `ApiError` retains its existing message/status behavior and now carries keyed `fieldErrors` and optional string `code`. Existing API error envelopes and constructor callers remain compatible.
- 409 pauses autosave, preserves further local input, exposes Copy unsaved changes plus selectable JSON fallback, and provides Discard unsaved changes and reload latest with explicit loss disclosure. A failed reload keeps all local values. No automatic merge or forced version override.
- Validation errors have stable field IDs, inline messages, `aria-invalid` and `aria-describedby`. Failed Continue focuses the linked error summary; keyboard activation reaches the corresponding email or repeated room field. Autosave errors do not move focus. Retry appears only for transport/408/429/server failures and retries the failed save/navigation action; validation, conflict, authentication and forbidden errors do not get a generic retry.
- Full verification at `61b0913`: **16/16 Chromium tests**, **41 API tests  / 277 assertions**, frontend typecheck, ESLint, production build and Pint all passed. Browser tests used 3203/8203 with the isolated worktree SQLite database and log mail. No skipped tests, weakened assertions, disabled rate limits or automatic retries.
- Browser red/green evidence includes the original conflict Retry-save failure, missing invalid-field ARIA, absent discard decision and lost city on browser Forward. One intermediate dev-server launch returned 404 on a valid onboarding route; a diagnostic restart and the full suite passed, and the production build passed. No source workaround was added for that dev-server event.
- Inspected screenshots: `apps/web/test-results/onboarding-field-errors.png` (focused linked summary and repeated-room inline errors), `onboarding-mobile.png` (390px conflict disclosure and retained input), and `onboarding-conflict-recovery.png` (copy/reload controls). These local test artifacts are regenerated by the suite and are not committed assets.
- Independent QA has accepted QA-01 at `870ad65` and QA-03 at `0f6fa0c`. Requested next: independent QA-02 retest and QA/design review of D-01/D-02 against `61b0913` (later report-only commit is equivalent code). This is not a full screen-reader/accessibility conformance claim.
- P2 mobile progress/layout, input sizing, room removal recovery, role explanations and Review edit links remain queued for product-owner assignment, as requested. No public-site work, shared catalog schema/routes or live integrations changed.

## Independent acceptance and Railway preparation

- Subsequent QA accepted the combined candidate across 14 scoped cases, including QA-02 recovery, stale async completion, key isolation and unavailable-storage warning. Design accepted D-01/D-02 from source/screenshots; this does not claim full runtime accessibility conformance. Approved report commit: `07309ccb9e665522c6c24b18c5d89ad753a14547`, equivalent implementation to `61b0913`.
- Added `docs/railway-api-staging.md`: concrete variable candidate, private frontend-to-API topology, stable key and session settings, PostgreSQL `DB_URL` reference, private photo volume, safe bounded log-mail use, proposed minimal runtime/proxy changes, migration ordering and verification gates. Public preview `e65d817` is not the approved private-backend baseline.
- Reviewed official Railway/Railpack documentation and current application configuration. PostgreSQL creation is user-reported; service/version/connectivity and API-service existence remain unverified. No hosted reads/writes, migrations, secrets or deployment actions were performed.
- Graft freshness check passed (329 nodes, wiring synchronized; deep layer intentionally absent). Documentation-only change: `git diff --check` passed; application/browser tests were not rerun. The prior 41 API tests / 277 assertions and 16 Chromium tests establish the approved local baseline, not Railway/PostgreSQL readiness.

## Railway deployment implementation candidate

- User confirmed empty service `niwadu-api`, database `Postgres`, frontend `https://niwadu-v2-production.up.railway.app`, and saved PostgreSQL variables. Source attachment/deployment remains outside this task.
- Added API-local Railpack/Railway configuration, guarded startup and pre-deploy scripts, production PHP/upload settings, and PHP 8.4/GD/PostgreSQL extension requirements. Lock metadata changed without package updates. Keep the private photo volume at `/app/storage/app/private`; configure service root `/apps/api` and Railway Config File `/apps/api/railway.json` separately.
- Startup refuses missing key/database/volume configuration, prepares caches, and starts FrankenPHP without migrations or shared-cache clearing. Pre-deploy checks PostgreSQL settings before migrations and does not require the runtime volume. No proxy trust changes or application contract changes.
- Red/green startup regression: missing script prevented valid startup; implemented guards and startup passed. Five subprocess tests / 37 assertions cover missing configuration, volume mismatch/absence, preservation of existing photos, forbidden restart actions, cache failure preventing server launch, and pre-deploy without a volume. External Artisan/server commands are test doubles in these script tests.
- Full API suite: **46 tests / 314 assertions passed**. Clean isolated locked `--no-dev --no-scripts` installation and platform checks passed; actual configuration/package/event/route cache preparation passed with an unreachable fixture database and synthetic key. No fixture database connection/migration ran. `view:cache` initially failed because no application views directory exists; removed that unnecessary step and reran successfully. PHP upload/error settings loaded correctly; shell syntax, Composer strict validation, Pint and diff checks passed. No frontend code changed or browser rerun required.
- Scoped Graft graph rebuilt after the new test and freshness rechecked. No deep/model/global graph work.
- Railway/Railpack official source/config docs reviewed. Neither Railpack nor Docker is installed locally, so no generated container execution is claimed. Actual image PHP/extensions/startup, PostgreSQL migrations/tests, private reachability/HTTPS/client-IP policy, writable volume ownership and photo persistence across restart remain runtime acceptance gates. No hosted changes, user secrets, live invitations, pushes, merges or deployments.

## QA correction: effective database driver

- Independent QA rejected deployment candidate `7e4c6df`: Laravel’s URL parser can override `DB_CONNECTION=pgsql` with a SQLite/MySQL URL or `?driver=sqlite`. Its environment-only guard was insufficient.
- Added `niwadu:check-deployment-database`, which resolves the actual Laravel connection driver without opening PDO, fails with a generic message on invalid/non-PostgreSQL configuration, and never prints the URL. Startup and pre-deploy invoke it immediately after clearing stale config and before caching/server/migrations.
- Regression coverage rejects SQLite/MySQL URLs and PostgreSQL query driver overrides, accepts PostgreSQL while asserting the PDO resolver remains an unopened closure, and proves checker failure stops both scripts. Actual clean no-dev pre-deploy runs also reject all three bad URL cases without migrations or credential output; valid PostgreSQL passes with an unreachable fixture host before/after configuration caching.
- This correction awaits independent QA disposition; it does not establish hosted PostgreSQL compatibility or runtime connectivity.

## Urgent isolated fix: deployed FrankenPHP welcome entrypoint

- Branch `fix/api-entrypoint`, isolated checkout based on published/QA-accepted `61ac6af`. No private-preview optimizer, EXIF, publication or PMS changes included.
- User's running API container reports `/app/public/index.php`: Laravel=no, welcome=yes. Source file is tracked and not ignored; approved SHA-256 is `eba77cba39695b6bd091fe5211d481f7ebb2ce2d8d26230b5a609465d0a4aff9`. Migrations succeeded because other application files remained present.
- Root cause verified in Railpack0.39.0 generated baseline plan: custom `install:composer` config implicitly exports its `/app` as a deploy input AFTER the completed build. That early layer includes FrankenPHP's bundled welcome index and overwrites Laravel's front controller. Official implementation: `core/generate/context.go` assigns default step deployOutputs `.`, and `buildkit/build_llb/layers.go` copies deploy layers in order. Upstream image installs its placeholder at `/app/public/index.php`.
- Set `install:composer.deployOutputs=[]`. Corrected generated plan retains base `build` and only the build output overlay; no final Composer-layer export. Add `verify-entrypoint.sh` plus PHP syntax validation during build, and the same entry guard before startup configuration work. Missing/placeholder entries fail closed instead of a misleading HTTP200 healthcheck. Existing PostgreSQL pre-deploy/migration guards and private volume/session settings are unchanged.
- Regression first failed because the placeholder still launched the server. Corrected startup suite passes7 tests/48 assertions; full exact baseline API suite passes50 tests/336 assertions. Pint and diff checks pass. Generated before/after plans in `/tmp/niwadu-railpack-before.json` and `/tmp/niwadu-railpack-after.json`, produced with `railpack plan --env DB_CONNECTION=pgsql`; no application keys or database URLs supplied. These establish plan composition, not execution of a Linux image. Docker is unavailable locally.
- Deployment path after independent QA/PO approval: publish ONLY this isolated branch/commit, select it for API service (root `/apps/api`), keep manual pre-deploy `sh pre-deploy.sh`, start `/start-container.sh`, health `/up`, and rebuild. No document-root guess or manual hosted-file patch. Verify running entrypoint hash/guard, then GET through frontend `/api/v1/session` must be Laravel JSON with guest user/CSRF and private no-store headers, not merely HTTP200. Frontend API_ORIGIN needs no change if it already reaches this API.
- No hosted edits, push or deployment performed by this task. Independent QA requested against the isolated candidate.

## Traveller account and private district coverage candidate

Implemented on isolated `feature/traveller-account`, based on QA-approved entrypoint fix `f0f73dc`; does not include the pending image optimizer or other component branches.

- `POST /api/v1/register`: name, normalized lowercase email, confirmed password (12–1024 characters; no NUL), no role/membership inputs. Creates existing User with null platform role and no hotel memberships, logs in through existing guard and regenerates session. Returns 201 existing `{csrf_token,user:{id,name,email,platform_role}}` envelope. Existing emails return 422 without mutation, authenticated registration returns 409, five attempts per minute; no mail is sent.
- `GET /api/v1/me/coverage`: session owner's `{districts,version}`, initially `[]`/0. `PUT` replaces entire list with expected version; 25 exact public reference slugs including `nuwaraeliya`, sorted output, no duplicates/unknown districts/ownership keys. Empty list supported. Owner row lock serializes first inserts and subsequent writes; stale version returns 409 message `Your coverage changed. Reload it before saving again.`. Staff may use their own map without privilege changes. Existing private/no-store, auth.session and CSRF middleware apply.
- New `user_coverage` table has owner primary foreign key with cascade delete, JSON districts, version and timestamps. No guest import, shared URLs, public map reads or new authentication framework.
- Local validation: red route tests first; full API 58 tests /395 assertions, Pint and diff whitespace checks passed using isolated in-memory SQLite and synthetic testing key. Cases cover registration/session/login/logout, staff denial, no membership creation, existing identity protection, rejected privilege/ownership fields, all25 districts, stale saves, own-user separation, cascade deletion, CSRF, guest denial and registration throttling. Actual PostgreSQL contention and real frontend/session integration remain independent QA checks; no hosted identity or deployment created.
- Existing Laravel bcrypt configuration retained, including its 72-byte effective password input limitation; changing global password hashing is not part of this candidate.

### Password byte-limit correction

Supersedes the candidate's 1024-character new-password maximum above. All password-setting entrypoints now share `PasswordBytes`: registration, token-based setup/reset, create-administrator and create-onboarding-employee require at least12 Unicode characters and at most72 UTF-8 bytes, reject NUL, and never truncate. Generated staff passwords are already64 ASCII bytes. Browser/operator messages state the byte distinction. Login retains its existing max1024 input/unchanged password behavior to avoid locking out existing accounts; previously created overlength bcrypt passwords still have their inherited suffix-equivalence limitation until reset. No global hash algorithm change or account migration.

Red tests proved overlength registration and reset were previously accepted; correction passes full62 API tests/424 assertions and Pint. New regressions cover both same72-byte-prefix suffixes rejected, multibyte overflow rejected/exact72-byte accepted, reset rejection preserves hash and usable token, both operator commands reject without account creation, and legacy overlength login remains compatible. Public owner updating registration and reset UI to match; independent regate pending.

## Coherent backend release integration

`integration/api-release` starts at traveller/password candidate97bc84b (independent real-browser QA PASS6c6d6af; includes entrypointf0f73dc). Added only catalog StageA1 room read/schema780b7f1, PostgreSQL harness69766d1 and discoveryb19a9fb deltas. Preserved current auth.session + PrivateApiResponse, registration/coverage throttles, password correction and deployment scripts. Conflict resolution retains modern route group and adds reviewed discovery routes; Hotel casts and onboarding property-type config merge without overwriting current ownership logic. Exactb19 hardened PostgreSQL bootstrap/migration test/config were used, then the PG suite was extended with traveller/password tests and a first-save/update coverage race test using the existing lock-barrier worker pattern.

Local combined SQLite API77 tests/632 assertions passed; Pint, PHP syntax of new PG race files and whitespace checks passed. New PG coverage contention checks await execution on the existing guarded isolated catalog PostgreSQL17 cluster; no hosted database used. No image optimizer, manual inventory, PMS, booking or unreviewed frontend changes included. Metadata discovery remains explicit administrator release, photo:null, no bookability or hotel-status change; no synthetic seeder runs in deployment. This is a local immutable integration candidate, not published/deployed until final QA and coordinator release authorization.

## Saved itinerary API candidate

Isolated `feature/saved-itineraries` starts at operations5aa4c35; no unrelated classification, key-hardening or optimizer changes. Existing authenticated session/CSRF/private-no-store routes at `/api/v1/me/itineraries` provide owner-only collection CRUD. List uses20-item Laravel resource pagination, newest updated_at/id; name trimmed1–120, ordered unique max20 stops with canonical89 slugs from frontend47c and nights1–14, empty list allowed. No dates, selected hotels/rates, prices or availability claims. POST returns201 data{id,name,stops,version:1,created_at,updated_at}; GETdetail same shape; PUT full name/stops/expectedversion increments; DELETE expectedversion returns204. Cross-owner access404 even for platform administrators. Mutations30/min; max100 active saved itineraries.

Create requires UUIDv4 `Idempotency-Key`, normalized lowercase and scoped to authenticated owner. User-row lock and unique(owner,key) serialize races; canonical normalized input hash rejects same-key/different-input409. Itinerary and original creation response persist atomically in one transaction. Matching replay returns original201 before quota checks, even after later edits/deletion; frontend must GET returned ID for current state or404. Replay never recreates deleted content. Creation records retain the original response for account lifetime, including after itinerary deletion; account deletion cascades both tables. No generic idempotency framework, no automatic guest import, no private browser storage.

New meaningful tests cover own CRUD/order/version conflicts, original response replay and deletion, owner separation, rejected injection/invalid stops/duplicate destinations/nights, quota/deletion, CSRF/guests, atomic ledger failure rollback,20-stop boundary, canonical key/payload replay and throttling. PostgreSQL test uses existing guarded cluster/worker pattern to prove simultaneous identical creates produce one record/one response and concurrent same-version updates produce200/409. That PG test awaits independent execution. Frontend candidate fcf348a awaits real pairing; no hosted operations or publication.
