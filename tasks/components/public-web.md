# Public web — initial inventory and homepage presentation

Date: 2026-09-23. Branch: `team/public-web`, based on `bad09b5`. This is a bounded frontend visual-preview candidate, not a bookable OTA or production cutover. No backend/schema changes, live submissions, merge, push or deployment.

## Delivered

- Public inventory: 153 discovered routes; 38 HTTP-checked endpoints (15 content/technical samples plus all24 hotel targets, with one overlap); 43 existing image downloads verified; 174 sampled image references with missing downloads explicitly recorded. Original URLs, capitalization, card order, descriptions and provenance retained in `docs/reference/public-web/`. Raw pages/session fields and tracking pixels are excluded from repository artifacts.
- Server-rendered homepage with all five actual sections (24 hotels, nine experiences, seven destinations), exact source photos, self-hosted Inter Tight, original dock icons and footer links. Cards point to current Niwadu routes while local detail pages await their own implementation.
- Real native scrolling and previous/next controls. Mobile unavailable-search dialog supports keyboard opening, focus containment, Escape/close and trigger focus return. No simulated search results, availability, checkout or currency conversion.
- Homepage canonical and concise truthful metadata, semantic H1/H2/H3 and named navigation. Preview stays `noindex,nofollow`. No invented Offer/rating/review/social-proof schema. Complete sitemap/redirect/structured-data work is documented and remains a launch dependency.
- Local image optimization at quality90; bounded 40-card reference eagerly loads after browser verification found native lazy images remaining blank in visible rows. Revisit offscreen loading with performance measurements when catalog-driven pagination/carousels replace this fixed reference fixture.

## Verification and limitations

`npm ci --no-audit --no-fund --prefer-offline`, lint, typecheck and production build succeeded inside this checkout with Node24. Production preview starts independently on3208; no Laravel/PMS/database process is required. Browser evidence is in `docs/reference/public-web/evidence/` and was captured against production `next start`.

Matched viewport geometry: 1440×1000 →245px header/210.828px card;1024×768 →245px/298.984px;768×1024 →80px/344.5px;390×844 →80px/318px. Corresponding DOM client widths1425/1009/753/375 reflect15px scrollbars. No page horizontal overflow; an additional320px resize check also passes. All visible images loaded in final captures; desktop initially loaded42 images with four hidden dock icons, and subsequent compact captures loaded46. Actual carousel JS confirms inclusive1024/768/576 cutoffs; CSS header/dock uses below1024. Source URLs, code excerpt and hashes are in `source-excerpts.md`.

`apps/web/tests/public-home.spec.ts` provides a focused regression for metadata/honest rate links/carousel/modal/overflow. Its scenarios were exercised through CUA browser assertions; the Playwright runner/full suite was not launched because QA owns its configured3101/8101 fixtures. Independent QA must execute it in the coordinated suite. Production browser console had no errors/warnings; `/` and `/api/health` returned200 with no backend running. HTTP HTML contains40 cards, oneH1, canonical and noindex. No full WCAG certification, production performance claim or design sign-off is implied.

## Approved differences and review requests

- PO: historical numeric prices replaced by “View current rates” in the same card area; no conspicuous disclaimer. Social-proof notifications omitted.
- User accessibility amendment via design lead: #536874 heading/details (instead of #8799a3), solid#184057 navigation/dock with white text,44px standalone controls, dual light/dark focus, reduced-motion support and dock safe-area/content clearance. Photos, Inter Tight, gradient and section order retained.
- Explicit unavailable search: desktop indicates limitation; mobile dialog contains an honest message and existing Hotels link, not an imitation working form. Currency is display-only. Full date/guest/currency surfaces remain pending actual contracts.
- Additional differences for design disposition: readable14px detail text retained on mobile (reference metrics report12px). Footer phone/email decorative glyphs are omitted. Native scroll rather than Glide is implementation-only; exact per-edge arrow disabled states improve honesty. Header navigation/scrollbar alignment and exact overlay tolerances still require independent design review.

## Dependencies / next bounded increment

1. Design review against exact implementation commit and screenshots, including authorized accessibility changes and the additional differences above. Original and extended baseline: design `7e2015b` and `6198896`.
2. QA run the focused public spec and check existing admin UI isolation; this CSS is scoped to `.public-site` except body margin selected by `:has(.public-site)`. Current unrelated auth blockers are not resolved by this frontend work.
3. PO/catalog approve published public projection and exact legacy slug mapping, ordered media/alt/dimensions, destination/experience editorial fields. Search contract must define URL/pagination/taxonomy/occupancy semantics. Prices require verified date/guest/room/currency/tax/freshness context. Draft onboarding JSON is never sold.
4. Future archive classification/filter/planner/map review has been read. No conflict with this static slice; do not derive amenity/theme claims from destination or treat dates as availability. Planner mode and map privacy remain PO/user decisions, not prerequisites for this preview.

## Railway visual-preview check

Use a separate frontend service with Root Directory `/apps/web`; its own package.json and package-lock.json are present. Build `npm run build`; start `npm run start -- --hostname 0.0.0.0 --port $PORT`; healthcheck `/api/health`. Added `apps/web/.nvmrc` containing24 so excluding the monorepo root no longer loses the version pin. Railpack can override it via higher-priority `RAILPACK_NODE_VERSION` or package runtime/engines settings; inspect actual build logs for Node24. See [official Railpack Node version resolution](https://railpack.com/languages/node).

For visual preview, keep the service disconnected from any live API and provide no API/database/payment/PMS credentials. Homepage/local images/healthcheck work without it; admin/account/booking backend operations are unavailable. Existing public links navigate to the current niwadu.com site. Keep the preview unindexable and clearly treat it as visual review. Do not merge unresolved auth changes for this purpose. A PO-managed preview branch can select this frontend commit without deploying Laravel. `API_ORIGIN` is baked into Next rewrites during build; a later approved API service requires build-time configuration plus rebuild, not merely a runtime variable edit. Railway deployment itself has not been tested or performed here.

## Independent preview review and dialog correction

QA independently passed the initial `d3781a9` production build/lint and three Chromium tests (focused public spec, no-backend/viewport/images/health, private CSS isolation), with API pointed at closed127.0.0.1:9. Design accepted remaining geometry,14px mobile detail and omitted decorative footer glyphs, but blocked mobile dialog text contrast.

A separate correction adds opaque white content backing with #184057 text, a navy/white Hotels link and light-blue/navy Close button while preserving the surrounding gradient. Measured computed colors confirm the opaque backing,44px Close and48px link. Production rebuild passed. CUA verified Shift+Tab/Tab wrapping and Escape focus return again; refreshed `evidence/search-unavailable-390.png`. Independent review of this correction remains required before PO publishes the Railway preview branch.

## 2026-09-24 — private admin frontend integration candidate

PO verified publication of `team/public-web` at `e65d8177e1d63c28eb2b148ccc721f460ec03f06` through GitHub's Git database API after Git upload timeouts; the bounded public preview had independent QA and design approval.

This next candidate imports only the five accepted admin application files and two regression test files from exact API/admin source `61ac6afd0885f830f071e9d4d78621ae879525f7` (frontend behavior finalized at `61b0913`). It adds session-expiry handling, explicit discard/sign-out, per-user/per-hotel tab recovery and conflict/validation recovery. Admin owner confirmed public-web owns integration. Public homepage, images, styles, metadata/noindex, rewrite configuration and test port configuration remain byte-identical to the approved preview.

Local lint and production build pass, including TypeScript. Scoped Graft structural cache refreshed against this working source on 2026-09-24 (72 files,314 nodes,588 edges); ignored cache is navigation context only. Independent QA must validate the exact committed candidate against a separate local API archive at `61ac6af`, including its accepted test seeder. Do not run the included integration tests against this branch's older bundled API or a hosted database. The password-session test reads the adjacent test API's local log, so QA should assemble the two exact archives as sibling apps/web and apps/api. QA owns3101/8101; production web testing uses `npm run build` followed by `npm run start -- --hostname 127.0.0.1 --port 3101`, with the local API target configured during build. This task has not touched live API settings or submitted live invitations/PMS/transactions.

Deployment coordination remains gated on independent QA. Use current Railway dashboard service settings; do not introduce new-service railway.json. Frontend root stays /apps/web with Node24/build/start/health settings described above. Backend deployment settings are owned by admin and PO, with railpack.json a distinct builder configuration. Hosted proxy scheme/client-IP and cookie behavior still require runtime validation after approved integration; local checks cannot prove the hosted login works. No instruction to connect the live API has been issued by this candidate.
