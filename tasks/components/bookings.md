# Bookings: first contract proposal

Status: lifecycle proposal with product-owner decisions below; pure synthetic manual quote calculator implemented. No public route, persistence or shared schema change. Owner: bookings; branch `team/bookings`; baseline `bad09b5bdf9bf071a9c52f727040dd074ab69fd2`. Read-only Surge source inspected at `8a842b08e545d61ae924daaf95338e5f06dd8c75` on 2026-09-23. This report supersedes no agreed API.

## Scope and recommendation

Start with one hotel, one room, one room type/rate plan resolved by rate_plan_id (no offering table), LKR integer minor units, adults only, and 1–30 hotel-local nights. No amendments, split stays, extras, partial cancellations, FX settlement or channel-ingestion booking path. The current slice is only a deterministic calculator with explicit synthetic complete inputs; payment and provider writes remain disabled. Existing onboarding room/rate JSON is not catalog identity or sellable stock.

Niwadu owns the checkout contract and guest consent. Catalog owns stable offering IDs, dated stock/rate inputs and atomic manual inventory operations. PMS owns adapters/mapping/capability evidence. Payments owns payment attempts, verified gateway events and refunds. Bookings coordinates these through durable intents in the existing Laravel backend, not new services.

## Evidence: source capability versus deployment capability

All paths below are relative to `/Users/rashmiassiriyage/frappe-bench-v16/apps/surge_hospitality`. No Frappe process, database, HTTP endpoint or test was executed. Source behavior is not sandbox verification.

| Evidence | Source observation | Consequence / unverified behavior |
|---|---|---|
| `surge_hospitality/api/v1/availability.py:262` (`quote`), `:28`, `:311` | Dated quote validates selection and quantity; token caches selection plus service user for 600 seconds. Token contains no accepted-price snapshot. | Token is not a price lock. Token expiry and inventory hold expiry are separate. |
| `surge_hospitality/api/v1/availability.py:89` (`entry_payload`) and `:302` | Quote totals come from one room's nights; `qty` is added separately. | Adapter must normalize quantity/rounding; first slice fixes quantity to one. |
| `surge_hospitality/api/v1/booking.py:44` (`hold`) and `:131` (`_hold_net`) | Hold rechecks availability/rates; returns gross, currency and UTC expiry. Reconstructed hold price can be recalculated. | Do not advertise a held price guarantee. Validate returned amount and expiry. |
| `surge_hospitality/inventory/holds.py:62`, `:160`, `:170`, `:194` | Inventory acquisition, row-locked conversion/release/expiry; default TTL 15 minutes when property TTL absent. Expiry worker describes a five-minute schedule. | Internal routines exist; deployed worker health and concurrency guarantees are unverified. No public hold lookup/release/renewal endpoint established by this inspection. |
| `surge_hospitality/api/v1/booking.py:372`; `surge_hospitality/reservations/doctype/sh_reservation/sh_reservation.py:69` | Confirm requires an active owned hold, creates/submits reservation, checks restrictions and prices reservation during validation. No expected-total argument checked before commit. | Even a re-quote immediately before confirm cannot close a pricing race. PMS checkout remains gated pending atomic expected-price/locked-price semantics or explicit approved commercial risk handling. |
| `surge_hospitality/api/v1/_common.py:269`, `:348`; `surge_hospitality/api/v1/booking.py:274` | Successful response replay is scoped by service user/endpoint/key; reservation insert handles duplicate keys. Wrapper replay does not compare a payload hash. | OTA must reject mismatched payloads itself. Provider key scope/retention, concurrent replay and credentials rotation behavior need sandbox verification. |
| `surge_hospitality/api/v1/booking.py:500`, `:530`, `:583` | Retrieval/cancellation by reservation or external reference; permission check against allowed properties. External-reference lookup can choose a live/latest record before property check. | Use stored provider ID when known; globally unique OTA external references; verify returned property/reference. A single not-found during timeout recovery does not prove creation failed. |
| `surge_hospitality/api/v1/booking.py:481`, `:547` | Confirm can optionally post a deposit; cancellation releases PMS customer credit according to policy. | Do not send `deposit` in initial adapter. PMS credit is not a PAYable refund. Settlement/accounting mapping needs its own agreement. |

Niwadu currently has session-authenticated `/api/v1` hotel/onboarding routes, snake_case resources and cross-hotel not-found behavior (`apps/api/routes/web.php`, `HotelResource`, `HotelPolicy`). It has no booking routes or booking permissions. Follow those conventions; do not infer booking access from hotel profile visibility.

## Proposed API surface

All routes are under `/api/v1`. Opaque OTA IDs only; no provider credentials, raw provider objects or client-supplied totals. Responses use `{data: ...}`. Browser checkout mutations require same-origin session/CSRF and throttling. A guest checkout principal must be defined before public rollout; until then use authenticated test actors. An identifier or email address alone never grants guest access.

| Method/path | Input | Output / semantics |
|---|---|---|
| `POST /booking-quotes` | `{rate_plan_id, arrival, departure, occupancy: {adults, children_ages: []}}` | 201 `Quote`. Server quantity fixed to 1 for first release. No guest PII. |
| `POST /booking-holds` | `{quote_id}`; `Idempotency-Key` | 201 `Hold` when acquired; 202 `Operation` when uncertain/in progress. Reject expired/changed quote; no silent repricing. |
| `POST /reservations` | `{hold_id, accepted_quote_id, guest: {first_name,last_name,email,phone?}}`; `Idempotency-Key` | 201 `Reservation` intent, or 202 `Operation` if provider outcome pending. Creation is not necessarily confirmation. At most one reservation intent per hold. |
| `GET /reservations/{id}` | Authorized principal | 200 `Reservation`, including current booking/payment/reconciliation states and safe next action. |
| `POST /reservations/{id}/cancellation-quotes` | `{}` | 201 `{id,reservation_id,reservation_version,expires_at,currency,fee_minor,refund_minor,policy_version}` calculated from accepted terms/payment ledger; unknown fee is an actionable blocker, not zero. |
| `POST /reservations/{id}/cancellations` | `{cancellation_quote_id,reason}`; `Idempotency-Key` | 201 `Cancellation` or 202 `Operation`; reject expired quote/version or fee changes. Provider acceptance and refund completion remain separate. |
| `GET /booking-operations/{id}` | Same owner or authorized hotel operator | 200 `Operation`; polling never triggers a second external mutation. |

Proposed value shapes (not migrations):

- `Quote`: `id, rate_plan_id, hotel_id, arrival, departure, occupancy, quantity:1, currency, nightly:[{date,base_minor,tax_minor,fee_minor,discount_minor,total_minor}], total_minor, due_now_minor, due_at_property_minor, policy:{version,text,free_cancellation_until,fee_rule}, quoted_at, expires_at`. Integer minor units with agreed currency exponent, no binary floats. Complete total includes all mandatory payable charges; if unknown, not bookable. Price/policy/source revisions are stored internally.
- `Hold`: `id, quote_id, state, expires_at, reservation_id?`. Active means stock actually acquired from its owner, not merely checked. Development hold ceiling is configurable with a 15-minute default, capped by provider expiry; final checkout timing is blocked on evidence. Keep provider reference, source/mapping version and raw diagnostic details private.
- `Reservation`: `id, hotel_id, quote_id, hold_id, state, payment_state, reconciliation_state, version, created_at, cancellation?, next_action`. Authorized detail includes accepted stay/price/policy and minimal guest contact; default polling response omits guest PII. Provider confirmation evidence remains internal.
- `Operation`: `id, kind, state:pending|succeeded|failed|unknown, resource_id?, status_url, retry_after_seconds`. Unknown is never represented as failed. Polling response and idempotent replay expose no cross-principal data.
- `Cancellation`: `id,reservation_id,state:pending|unknown|accepted|rejected,fee_minor,refund_minor,refund_state`. Amounts are frozen consented terms, not a claim that funds moved.

Dates are hotel-local `YYYY-MM-DD`, arrival inclusive/departure exclusive. Instants are RFC3339 UTC, with hotel's IANA timezone stored separately. Reject invalid stay length, unsupported occupancy/child ages, inactive/unpublished offering, missing nights, stop-sell, restrictions and stale PMS projection. Do not silently coerce children to adults. Bounds and supported occupancy require catalog agreement.

Use Laravel's existing `{message,errors?}` error envelope; add a stable `code` field for booking domain failures, preserving framework auth/validation behavior. 422 invalid input; 404 inaccessible/missing resource; 403 denied action on accessible resource; 409 `quote_changed`, `hold_expired`, `invalid_transition`, `idempotency_payload_mismatch`; 429 throttled; 503 dependency unavailable **before a side effect is attempted**. An ambiguous attempted write returns 202 with durable operation ID. Do not expose raw provider errors or guest fields. Use no-store and noindex for checkout/private responses.

## Price and dated inventory invariants

1. Reconstruct authoritative selection from stable catalog IDs and source ownership; never accept amount, ownership mode, provider IDs or payment status from the browser.
2. Quote every night, tax, mandatory fee, restrictions, occupancy and cancellation/deposit terms. Store immutable consented snapshot and revisions. A quote is informative until an owner-backed hold is acquired.
3. At hold, revalidate all nights and price/policy. A changed result requires a new quote and explicit guest acceptance. Manual acquisition locks all affected nightly rows in consistent order in one transaction; missing inventory is unavailable. Catalog owns that operation and must prove concurrency on the chosen SQL engine, not SQLite alone.
4. At confirmation, atomically consume an active hold with accepted terms. Manual held-to-sold movement is exactly once and must not double-decrement. PMS must provide equivalent acceptance/price semantics; cached availability and separate checks cannot guarantee them.
5. Persist source mode, provider connection/mapping revision and quote version on the intent. Disconnect/stale provider stops affected sales. Manual stock is a separately configured offering/source, never an automatic fallback. Ownership cutover must account for unresolved bookings/holds.
6. OTA expiry uses the earlier of provider expiry and agreed local deadline, with a transport safety margin. `now >= expires_at` is expired. Do not extend on retry. Expiry/release and conversion serialize; expiration must not release stock already converted. An outstanding ambiguous confirm is reconciled, never assumed undone because the clock passed expiry.

## Minimal state model

Keep independent state axes to avoid equating money with reservation acceptance.

| Aggregate | Transitions and guards |
|---|---|
| Quote | Immutable snapshot; usable only before expiry and with matching source/policy revisions; replaced by a new quote on change. |
| Hold | `pending -> active|rejected|unknown`; `unknown -> active|rejected` only with evidence; `active -> converted|released|expired`. Internal `release_pending`/operation tracks uncertain provider release; do not claim released on a request alone. |
| Reservation | `pending -> confirmed|rejected`; ambiguous confirmation stays pending with reconciliation required. `confirmed -> cancellation_pending -> cancelled` on authoritative acceptance, or back to confirmed on definitive cancellation rejection. No direct guest/staff status setter. |
| Payment (owned by payments) | Minimum normalized observations: `not_started,pending,authorized,succeeded,failed,unknown,refund_pending,partially_refunded,refunded`. Only advertise authorization/void if PAYable actually supports it. |
| Reconciliation | `none,required,in_progress,resolved,manual_review`. Bounded retry schedule and platform-owned operational queue; store reason, last evidence and next check. |

`confirmed` means authoritative inventory owner accepted the reservation with secured inventory and matching agreed commercial terms. A positive PAYable callback cannot set it. The guest may receive a booking confirmation only when secured-inventory acceptance and the approved payment requirement both hold; other combinations show a precise pending/payment-action state. Duplicate/delayed events cannot regress terminal decisions; all transitions lock/version the current aggregate and record actor/evidence.

Hard acceptance condition from product-owner/PMS coordination: never confirm checkout from channel ingestion or provider status text alone. Independently inspected `surge_hospitality/api/v1/channel.py:249`: ingestion passes `allow_oversell=True` and returns oversell flags. `surge_hospitality/reservations/booking.py:279` rolls back inventory acquisition on shortage and sets `is_oversell=1` in that path. Require validated hold/confirm-path evidence, matching hotel/selection/price, and no oversell (`is_oversell`/`oversell` must not be true); missing required inventory evidence is unknown/manual review, not confirmation. Compare this interpretation against the PMS owner's final capability report before adapter implementation. No runtime guarantee is claimed.

## Idempotency, timeouts and compensation

Persist each write intent before any remote call. Atomic uniqueness for `(principal, hotel, operation_kind, idempotency_key)` plus canonical request fingerprint; bind the key to the specific resource ID too. Matching completed retry returns the original response/status; matching in-progress/unknown retry returns the same operation with 202. Different payload returns 409. Reauthorize every replay against current principal/membership. A different key must not create a second confirm for the same hold, second cancellation, or second payment for the same payment intent.

Provider keys and external references derive from durable OTA operation/reservation IDs, never an HTTP attempt timestamp. Persist the exact outbound payload, provider/mapping version and key before dispatch. OTA protection does not imply provider exactly-once guarantees. Crash after dispatch is unknown even if no response was saved. Retain financial/booking deduplication identities for the full reservation/refund lifecycle plus the agreed maximum gateway/queue replay window; never expire unresolved records. Payments/product owner must set that window before launch.

Reconcile by stored provider ID or unique external reference and validate hotel, dates, selection, price/currency and state. A not-found response may race the original transaction. Retry a mutation only once the adapter establishes a safe same-key replay guarantee or definitive non-application; never issue a fresh key to bypass uncertainty. Conflicting/malformed provider evidence moves to manual review. Use a durable pending-operation table and worker first; queue dispatch, if added, must not lose an intent between database commit and enqueue.

| Event combination | Required action |
|---|---|
| Payment succeeded; confirm still unknown | Show processing, reconcile provider; neither another charge nor a speculative second booking. Escalate after agreed SLA. |
| Payment succeeded; booking definitively rejected/expired before confirmation was dispatched | Enqueue one refund/void intent for the eligible captured/authorized amount; report refund pending until gateway verifies completion. |
| Booking accepted; payment failed/unknown | Do not claim completed checkout. Resolve payment first; if approved checkout policy requires compensation, request cancellation with one durable intent and reconcile its result. Never assume cancellation is free. |
| Cancellation accepted; refund pending/failed | Booking remains cancelled; refund tracked separately and actionable to platform operations. |
| Late payment success after expiry/rejection/cancellation | Do not resurrect a reservation or acquire fresh stock. Reconcile duplicate event and enqueue the appropriate single refund intent. |
| Late booking acceptance after compensation began | Record actual acceptance; reconcile cancellation and money together. Do not hide a real provider booking behind a local rejected flag. |
| Provider price/policy mismatch after acceptance | Commercial exception/manual review; no extra charge or claim of agreed-price success. This is a launch blocker without an agreed compensation/risk policy. |

Do not choose charge-first versus book-first until payments/PMS confirm their actual capabilities. Authorization-before-confirm/capture-after is only a candidate if supported. Capture-first needs proven refund handling; confirm-first needs proven cancellation/penalty handling. No PAYable sandbox exists, so no live checkout path is ready.

## Tenant access and guest privacy

Booking policy must check hotel membership and action permission independently of `HotelPolicy::view`. Proposed permissions: hotel manager/reservations staff may read assigned-hotel reservations and request allowed cancellations; inventory managers/onboarding employees gain no guest-booking access by default. Viewers receive no guest PII. Manual reconciliation is restricted to platform administrators; no unverified automatic refunds. Platform cross-hotel booking support is explicit and audited; gateway/PMS configuration remains separately restricted to authorized Niwadu platform staff.

Scope nested IDs, jobs, idempotency lookups, exports and polling by hotel and principal. Recheck revoked memberships at action and replay time. Return 404 for cross-hotel identifiers. Guest access requires an authenticated owner or high-entropy, expiring, revocable capability exchanged for a secure session; never booking number + surname/email alone. Do not put guest identity or bearer secrets in analytics/referrers/logs.

Collect contact data needed for this reservation only; omit identity documents, nationality and persistent preference profiles from initial payload. Surge's guest matching by email and preference accumulation require adapter/privacy review. Allowlist provider response fields; no raw guest/customer/folio objects to browsers. Encrypt sensitive persisted payloads, redact logs and audit actor/action/reference rather than full bodies. Set retention/deletion policy before launch; operational deduplication keys should survive PII minimization without retaining unnecessary contact details.

## Implemented bounded slice: synthetic manual quote calculation

`apps/api/app/ManualQuoteCalculator.php` is a pure function-like class. `calculate(input, now, expiresAt)` takes complete synthetic nightly amounts and explicit immutable clock instants. It returns the accepted-input policy snapshot, stay, nightly totals, LKR total and UTC timestamps; it creates no quote identity, persistence, reservation or stock guarantee. Invalid/missing/extra fields throw `InvalidArgumentException`. No route or application caller exists.

The fixed-clock tests supply room-inclusive, occupancy-specific base/tax/mandatory-fee amounts for every night and explicit availability/restriction facts. Zero charges must be explicit; incomplete charges, unsupported occupancy, stale expiry, local past arrival, missing/duplicate/out-of-order nights and integer overflow fail closed. Arrival is inclusive, departure exclusive. Expiry is caller supplied for validation only, not a chosen checkout or hold TTL.

Missing real catalog binding: catalog must resolve hotel/room/rate relationships, publication/readiness, manual ownership, current occupancy pricing, restriction results, dated stock/rates, source freshness/revisions, and complete policy/mandatory-charge facts. Numeric IDs and synthetic available counts here do not prove those things. The calculator neither accepts HTTP input nor authorizes users; authentication, guest consent, fingerprints, immutable storage and hold integration remain later work. A non-empty synthetic policy is not proof of legally/commercially complete cancellation rules. No arbitrary discount/FX/deposit calculation is introduced.

Reconciled with catalog's report: stable integer room/plan IDs; one shared room pool; catalog owns atomic holds and stock transitions; bookings owns quote/intent/guest lifecycle. Catalog's proposed amount_minor needs explicit decomposition or an agreed tax-inclusive representation before binding; no invented zero tax/fee normalization. Quantity is fixed to one despite the broader catalog proposal. PostgreSQL 17 independent-connection tests are the agreed future concurrency evidence; this pure slice makes no concurrency claim.

PMS requirement remains a channel-manager integration. Direct Surge hold/confirm is source evidence only and is not a substitute implementation. PMS checkout remains disabled until the chosen channel-manager path proves atomic inventory and accepted-price guarantees.

## Concrete decisions requested through product owner

1. **Catalog/inventory:** remaining binding work is per-night rate/tax/fee/occupancy/restriction shape, policy snapshot/version, source revision/freshness and publication readiness. Ownership, rate_plan_id, quantity one, LKR, PostgreSQL 17 and development hold ceiling are decided. Catalog's inspect/hold contract must supply complete charges without defaulting unknown tax/fee to zero.
2. **PMS:** verify hold price guarantees (source currently does not show one), expected-total atomic confirmation, rounding/quantity semantics, property-scoped retrieval, hold lookup/release, safe retries/key retention, scheduler health and cancellation fee evidence. Supply capability matrix and sandbox-only fixtures for accepted/rejected/timeout/late-success. No code changes requested to live Surge. A price recheck alone is insufficient.
3. **Payments:** define normalized payment events with durable event/payment/reservation IDs, verified amount/currency, auth/settled status, verification timestamp, deduplication and out-of-order behavior; choose payment order only after official PAYable capability evidence. Confirm sandbox/access, refund/void/status lookup, partial amounts/fees, replay window and handling of late success. Propose `request_refund(payment_id, reservation_id, amount_minor, currency, reason, idempotency_key)` returning a durable intent, not synchronous proof of refund.
4. **Product owner/access:** role defaults, authenticated test principals and platform-administrator reconciliation ownership are decided. Remaining decisions are cancellation consent details, reconciliation SLA and price-risk policy; public guest credentials remain a later bounded slice. No new end-user permission question is needed for these team contracts.

## Verification and handoff

Read `AGENTS.md`, app instructions, `docs/requirements.md`, `tasks/plan.md` and the shared coordination board. No `.ai/rules` directory exists in root or API checkout. Inspected Laravel routes/resources/policy, catalog's component report and the Surge source paths above. No services started, existing secrets read, shared schema changed, PMS writes, live bookings/payments/invitations, push, PR, merge or deployment performed. Full backend suite uses isolated in-memory SQLite and MAIL_MAILER=log; no PMS database was accessed. This is not PostgreSQL concurrency evidence.

Deliverable is the pure calculator, focused tests and this reconciled proposal. Real catalog binding is deliberately absent and requires the complete pricing input contract before another bounded increment.

## Product-owner decisions incorporated in increment 2

Approved manual-first, PostgreSQL 17, rate_plan_id resolution without an offering alias/table, catalog-owned holds, quantity one, adults only, 1–30 nights and LKR. Managers/reservations staff have hotel-scoped guest access; viewers/inventory/onboarding do not. Start with authenticated test principals in later routed slices; public guest sessions are deferred. Platform administrators own initial manual reconciliation; refund execution requires verified evidence. The earlier lifecycle endpoints are proposals only. No automatic refunds, PMS checkout or charge/book ordering has been implemented.

Validation: test-first run failed because calculate did not exist; implementation then passed 33 focused tests / 39 assertions. PHP 8.4, locked Laravel 13.33.0 and PHPUnit 12.5.35; dependencies installed only in this checkout. Pint applied. Full backend suite passed 59 tests / 188 assertions after supplying an ephemeral test-only APP_KEY (initial regression run failed for the missing key, not calculator behavior). No .env secrets were copied. Whitespace check passed; local commit is reported in the handoff. The referenced testing-best-practices skill was absent from installed skills; existing PHPUnit conventions and the available TDD skill were used. No browser tests needed for pure PHP with no UI.
