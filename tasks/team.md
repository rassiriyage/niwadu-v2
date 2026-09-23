# Niwadu delivery team

Product owner: this task, `01a0ca89-da16-7280-890e-6902df930d72`. The product owner owns priorities, acceptance criteria, shared contracts, cross-task decisions and integration. The user requested separate component tasks plus independent QA and design leadership.

## Ownership and first assignments

| Task | Ownership | First bounded deliverable | Dependencies |
|---|---|---|---|
| Hotel administration | Platform/hotel access and onboarding UI | Create onboarding employees through a secure operator command; preserve privilege boundaries | Existing access/onboarding; QA/design review |
| Catalog and inventory | Stable room IDs, media mapping, rate plans and dated allotments | Propose the catalog/inventory contract and database choice before shared schema implementation | Product-owner contract decision |
| Bookings | Quotes, holds, reservation lifecycle and cancellation | Booking API/state contract, idempotency and failure cases | Catalog, PMS and payment contracts |
| PMS connections | Provider adapters, mappings and sync | Verify Surge capabilities and propose provider contract | Catalog and booking contract decisions |
| Payments | Platform-owned PAYable, callbacks and reconciliation | Official provider capability/access assessment and payment contract | Sandbox account, booking contract |
| Public website and SEO | Exact Niwadu reproduction, migration URLs/content, SEO/AEO/GEO | Inventory current routes/assets and propose a homepage implementation slice | Design baseline and public catalog API |
| QA and code quality | Independent validation, security/isolation, regressions and bloat | Audit PRs #1/#2 and reproduce focused failures; issue prioritized findings | Tested commit hashes from component owners |
| Design lead | Reference style, tokens, accessibility, visual/usability review | Capture existing Niwadu baseline and review private onboarding UX | Current site and screenshot evidence |

## Working rules

- Each task has an isolated worktree under `/Users/rashmiassiriyage/niwadu-worktrees/`; all start at `bad09b5` (the onboarding implementation), not the older main branch.
- Never edit another task's checkout. Cross-component changes and shared contracts come back to the product owner.
- Components own implementation; QA and design produce independent findings/sign-off against specific commits. QA checks security, meaningful tests, clarity, dependency necessity, duplication and needless abstractions. Design checks public fidelity and private-workflow usability.
- No merge is treated as ready solely because tests pass. Acceptance criteria, QA findings and applicable design findings must be resolved or explicitly dispositioned by the product owner. No production deployment or real payments/bookings/invitations/PMS updates.
- Default to simple changes using existing dependencies; no speculative framework layers. No test weakening or suppression to make a check pass.
- Initial integration order: QA/design baseline → shared contracts → catalog/manual inventory → booking path → Surge and PAYable sandbox integration → public checkout and launch verification. Public content/design discovery and operator tools can progress independently.
- QA exclusively uses the existing Playwright ports 3101/8101. Other tasks must use their assigned private ports or coordinate before running that suite. Each worktree uses its own test database; never use the PMS database.
- Reports include commit/PR, changed behavior, verification evidence, open findings and dependency requests. Ongoing work is dispatched in bounded increments by the product owner.

## Current review baseline

- PR #1: hotel access, `https://github.com/rassiriyage/niwadu-v2/pull/1`.
- PR #2: guided onboarding, `https://github.com/rassiriyage/niwadu-v2/pull/2`, stacked on #1.
- Local verification at `bad09b5`: 26 API tests (149 assertions), seven browser scenarios, frontend lint/type/build and feature-diff secret scan passed. Independent QA/design review is now requested; prior local results are evidence, not a substitute.

## Task directory

- Niwadu — QA and code quality: `01a0cd9e-01d1-7f82-8467-3a7c6dfd81c6`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/qa`; branch `team/qa`.
- Niwadu — Design lead: `01a0cd9e-1522-79d2-a651-8382ed7abd0f`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/design`; branch `team/design`.
- Niwadu — Hotel administration: `01a0cd9e-27fb-7a71-aac6-a8a5a5b46f64`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/admin`; branch `team/admin`.
- Niwadu — Catalog and inventory: `01a0cd9e-3cb1-7f01-83ae-6f233ed059ff`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/catalog`; branch `team/catalog`.
- Niwadu — Bookings: `01a0cd9e-5a8d-7842-9f14-f26cff3f028b`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/bookings`; branch `team/bookings`.
- Niwadu — PMS connections: `01a0cd9e-7d68-7d90-90d4-47916060a817`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/pms`; branch `team/pms`.
- Niwadu — PAYable payments: `01a0cd9e-9b4b-7bd0-904d-5614ae4175f1`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/payments`; branch `team/payments`.
- Niwadu — Public website and SEO: `01a0cd9e-d7a0-7dc3-af9b-f1927d677647`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/public-web`; branch `team/public-web`.

## Product-owner decisions — first component review

- Design baseline `7e2015beb304057b171dc02be87631ba035ef310` is accepted as evidence for the initial homepage slice, not full-site or onboarding sign-off. Public owner may implement that slice with fresh-load desktop/mobile comparisons. Dynamic social-proof facts are omitted until supported by verified data; no invented visitor/availability claims.
- Administration completed employee provisioning at `898eac6edcf4a373020be22b8ff2c1f6b62b58db`; independent QA is requested. Its next increment resolves design P1 D-01 (409 recovery) and D-02 (field-linked validation). Approve additive `ApiError` fieldErrors/optional code, preserving existing message/status/envelope. Reload-latest must disclose discard and preserve/export unsaved fields first; retry is reserved for recoverable failures. P2/P3 UX findings remain queued.
- PostgreSQL 17 is the selected dedicated Niwadu development/concurrency engine. Catalog owns isolated configuration/provisioning planning, beginning with local capability discovery; hosted costs/service installation are not part of this dispatch. SQLite remains useful for fast tests, never proof of production locking.
- Catalog uses stable integer room/rate-plan IDs; `rate_plan_id` resolves the sellable selection without an extra offering table. One inventory pool per room type is shared by all its plans. Mixed manual/PMS ownership for those plans is unsupported initially.
- Catalog Stage A1 is room identity, structured same-hotel photo associations, scoped reads and tests. Do not expose conversion or freeze editable room drafts until a usable catalog edit destination and explicit conversion flow exist. Catalog alone owns the next catalog migrations.
- The first manual booking slice uses one room, one room type/plan, 1–30 hotel-local nights, LKR integer minor units and adults only until child pricing is modeled. Unsupported child pricing must be rejected explicitly. Unknown mandatory taxes/fees are never treated as zero. Fifteen minutes is a configurable development hold ceiling, capped by provider expiry, not a production checkout guarantee.
- Inventory owns atomic hold/commit/release records; bookings owns quote/intent/guest lifecycle. Managers and reservations staff may access guest data only for assigned hotels; viewer/inventory/onboarding roles get none by default. Authenticated test principals precede a public guest-session slice. Initial financial/reconciliation operations are platform-administrator only.
- Independent PMS/bookings source reviews at Surge `8a842b08e545d61ae924daaf95338e5f06dd8c75` found channel acceptance without inventory assurance and no held-price guarantee. Preserve the user's channel-manager requirement: do not silently substitute direct hold/confirm. PMS checkout stays disabled. PMS owner will specify a minimal channel contract extension with atomic hold/price acceptance, deduplication/accounting/lookup; no changes to the PMS checkout are authorized in this dispatch.
- Bookings may implement a pure deterministic quote calculator against explicit synthetic complete nightly inputs, with no routes, persistence or claimed catalog integration. Payment ordering stays unresolved until provider evidence exists. Late payment cannot revive expired stock; ambiguous provider confirmation must be reconciled before compensating actions.
- PAYable owner will resolve official documentation/version/signature and refund/lookup gaps and prepare a concise provider question list. No ambiguous signing formula is accepted as verified, and no generic placeholder payment framework is needed while sandbox/provider evidence is missing.

Evidence reports live in each component's `tasks/components/` directory. QA and design remain independent review gates; no component report alone authorizes deployment or a merge.
