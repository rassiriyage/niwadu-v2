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
+## Task directory

- Niwadu — QA and code quality: `01a0cd9e-01d1-7f82-8467-3a7c6dfd81c6`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/qa`; branch `team/qa`.
- Niwadu — Design lead: `01a0cd9e-1522-79d2-a651-8382ed7abd0f`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/design`; branch `team/design`.
- Niwadu — Hotel administration: `01a0cd9e-27fb-7a71-aac6-a8a5a5b46f64`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/admin`; branch `team/admin`.
- Niwadu — Catalog and inventory: `01a0cd9e-3cb1-7f01-83ae-6f233ed059ff`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/catalog`; branch `team/catalog`.
- Niwadu — Bookings: `01a0cd9e-5a8d-7842-9f14-f26cff3f028b`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/bookings`; branch `team/bookings`.
- Niwadu — PMS connections: `01a0cd9e-7d68-7d90-90d4-47916060a817`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/pms`; branch `team/pms`.
- Niwadu — PAYable payments: `01a0cd9e-9b4b-7bd0-904d-5614ae4175f1`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/payments`; branch `team/payments`.
- Niwadu — Public website and SEO: `01a0cd9e-d7a0-7dc3-af9b-f1927d677647`; worktree `/Users/rashmiassiriyage/niwadu-worktrees/public-web`; branch `team/public-web`.
