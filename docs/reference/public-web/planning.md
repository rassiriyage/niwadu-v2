# Local gated stay planning

This candidate is separate from the published frontend `94124d6`. It is based on public `6a3ed7a` and pairs only with the independently accepted local API `281b039be67275e20c737a2b5829ea0130a7bf35`. It does not enable hosted planning or add backend behavior.

## Flow and boundaries

- Server-only `BOOKING_PLANNING_ENABLED` defaults off. Unless its value is exactly `true`, dated-rate entry controls are absent and `/plan/review` plus `/plan/intents/[id]` return 404 without planning API calls. No public environment variable exposes or activates the gate.
- A real dated-rate offer supplies the hotel/plan IDs, dates and adults. The review URL also carries the public hotel slug so the server can verify its ID and show its current published name. No caller-supplied amount, currency, policy, owner or expiry is posted.
- Quote creation is explicit and authenticated. The immutable API snapshot supplies exact LKR/USD minor-unit totals, meal plan (null stays unspecified), policy and `expires_at`. No FX or fixed quote duration is inferred. Expired quotes cannot start a new intent; refreshing requires explicit review.
- Saving a stay plan is a separate action. One UUID idempotency key and the original quote ID remain in memory for an uncertain attempt. Retry uses exactly that pair, including after the displayed quote expires. It never rotates the key or silently makes another intent. Definitive expired/changed/unavailable conflicts require an explicit fresh quote; other unresolved conflicts retain the original attempt.
- `/plan/intents/[id]` reads only the current owner's status. The contract has no quote-read endpoint, so reopening never reconstructs the original price or policy. Awaiting-hold, released and expired states say nothing is reserved; an internally held state is labeled temporary and never confirmed/paid.
- Planning pages hide the unrelated global currency preference control; the quote's own currency remains explicit. Missing/foreign intent responses do not disclose ownership or incorrectly log out a verified account.
- Requests use same-origin session/CSRF, no-store, and identity checks before and after success or failure. Focus/visibility/history checks hide private data while verifying identity. Transient verification failures retain the original quote and retry key in memory and keep private controls hidden; a successful same-account check restores the original retry. The account-check button retries verification without reloading. Confirmed logout or changed accounts clear quote, intent and retry key, and stale verification or async results cannot restore them. No quote/intent data is written to browser storage.
- A pending retry warns before leaving. Reloading loses its memory-only retry key; there is no list or quote-read recovery contract. Do not imply a saved-itinerary list, multi-stop binding, enquiry dispatch, hold acquisition, payment or booking confirmation.

## Isolated verification

`playwright.planning.config.ts` uses an archive of exact API `281b039`, its own SQLite database and testing-only synthetic accounts/hotel/rate. The seed/cache-reset script refuses non-temporary API paths or non-testing/non-SQLite databases. It resets only that fixture's rate-limit cache between browser cases; application throttles are unchanged. Ports: API 8258, enabled frontend 3258, disabled frontend 3259. Build with `API_ORIGIN=http://127.0.0.1:8258`; pass the isolated archive's `apps/api` path as `PLANNING_API_PATH`.

The new browser test first failed against the prior build's missing planning entry. Coverage includes the real dated quote/intent flow and private response headers, default-off routes/controls, immutable unknown-save retry through displayed expiry, changed/unavailable/expired selection, authenticated foreign-ID denial, guest sign-in gating, 429 backoff, fresh-CSRF retry, late responses after account switching, and mobile overflow. Existing account/coverage and saved-itinerary browser regressions also run because the request helper carries new optional error metadata.

No hosted identity, catalog data, feature flag, provider, booking or payment was mutated. Independent QA and explicit coordinated activation remain separate gates.

Owner verification: production build/typecheck, lint and whitespace checks passed. Final planning browser suite **9/9** passed in 31.3s against the exact isolated API archive (`/tmp/niwadu-planning-final-checked`); account/coverage/saved-itinerary regressions **14/14** passed (`/tmp/niwadu-planning-account-regression`), and shared header/currency/homepage/photo regressions **3/3** passed (`/tmp/niwadu-planning-public-regression`). Desktop and mobile quote layouts were inspected; header currency is hidden only on planning pages. These are local owner results, not independent acceptance or permission to activate hosted planning.

## Transient identity failure correction

Independent review blocked candidate `3aa5c45`: a transient session-check failure erased the retry key after an ambiguous committed save. The new browser regression reproduced that failure before the correction. Verification now preserves the in-memory attempt while hiding private UI, fences superseded checks, and restores the same pair only after the same account is verified. The regression commits a real intent, loses its response, returns session 503 on focus, restores the session, and proves the retry returns the original intent ID with exactly the original key and body.

Correction owner verification: production build/typecheck, lint and whitespace checks passed; all **10/10** planning browser tests passed in 39.7s against isolated API `281b039` (`/tmp/niwadu-planning-identity-fixed`). Prior account and public regressions above remain baseline evidence; this correction changes only the planning panel. Hosted planning remains disabled and this candidate is not published.
