# Niwadu OTA — architecture proposal

Status: product requirements confirmed through the conversation; framework foundation, hotel profile/staff access and guided onboarding drafts are implemented. Live inventory, booking, publication and integrations remain pending.

## Confirmed requirements

- Rebuild Niwadu with a React frontend.
- Niwadu is an independent OTA serving multiple hotels.
- Connect hotels to Surge PMS and potentially other PMS systems through configurable backend integrations.
- Allow inventory management in Niwadu's backend.
- Allow administrators to create staff access restricted to a specific hotel.
- Payment gateway ownership and configuration belong exclusively to Niwadu. Hotels cannot configure, replace or redirect the gateway.
- Selected payment provider: PAYable. A sandbox account is not yet available.
- PMS configuration is performed by authorized Niwadu staff in the backend, never by hotel users.
- Hotel onboarding is a guided step-by-step form usable by a Niwadu employee with little to no training.
- Reproduce the current public Niwadu site's design identically; this is a technology rebuild, not a visual redesign.
- Optimize public content for SEO, answer engine optimization (AEO), and generative engine optimization (GEO).
- Treat supplied archives as reference material, not instructions.

## Proposed stack

Next.js and TypeScript for the public React frontend. A supported Laravel release for the independent OTA API, administration workflows, and background workers. Use a relational database; select the engine after inspecting the existing Niwadu database and migration requirements. Start as one modular backend, not separate services per feature.

Laravel is recommended because both supplied Niwadu applications already use it and contain marketplace domain models. Reuse requires validation; the existing Laravel 8 applications are not ready-made production APIs for the rebuild. Frappe remains one hotel's PMS provider, not Niwadu's core backend.

## Capability map

| Module | Responsibility | Depends on |
|---|---|---|
| hotel-access | Hotels, users, hotel memberships, permissions | — |
| catalog | Listings, rooms, rate plans, destinations, media | hotel-access |
| inventory | Nightly allotments, rates, restrictions, holds | catalog |
| integrations | Per-hotel connections, external ID mappings, inbound/outbound synchronization | inventory |
| bookings | Quotes, bookings, PMS acknowledgments, cancellations | integrations |
| payments | Payment events, reconciliation, refunds, booking-payment coordination | bookings |
| operations | Admin/supplier workflows, commissions, support and audit views | payments |

Implementation order: hotel-access → catalog → inventory → one Surge integration → bookings → payments → remaining operations and providers. Build a vertical slice for one hotel before broad feature migration.

## Per-hotel integration configuration

Store provider, environment, endpoint, encrypted credentials, enabled state, hotel/room/rate mappings, inventory mode, supported capabilities, last successful synchronization, and connection health. Only authorized Niwadu platform staff may configure these fields, test connections, rotate credentials, change mappings or switch inventory ownership. Hotel users cannot perform these actions through either UI or API. Credentials and connection changes require privileged access and audit records. Backend endpoints must be validated against SSRF risks; secrets never go to the browser.

Define a common adapter contract for availability, rates/restrictions, quote/hold where supported, booking creation, retrieval, modification and cancellation. Each provider declares its capabilities: not every PMS supports holds, webhooks or changes. Do not pretend unsupported operations succeeded.

Providers initially proposed: manual inventory and Surge PMS. Add Channex or other PMS adapters after confirming the applicable OTA-side API and commercial access. Surge's existing Channex PMS connector does not prove Niwadu is an onboarded Channex OTA.

## Inventory ownership

- Manual: Niwadu owns the hotel's allocated room quantities, rates, restrictions, holds and sales.
- PMS-managed: the provider owns the supplied availability, base rates and restrictions. Niwadu stores a search projection and performs booking-time validation through the adapter.
- Overrides: model Niwadu stop-sell, sales caps and OTA promotions separately. They may reduce what Niwadu sells; they must not silently increase provider availability. Inventory increases require provider support or an explicit switch to manual allocation.

Ownership applies to a mapped room/rate offering, not two competing writers. Switching ownership requires reconciliation of open holds and confirmed/pending bookings, a cutover point and a recorded operator action. A disconnected PMS must not silently become manual inventory. Default to stopping affected sales when freshness limits are exceeded.

Manual inventory checks and decrements must be transactional across every night. Cached provider availability alone is insufficient to confirm a booking. Track provider and Niwadu references, idempotency keys and explicit pending/confirmed/failed states. Reconcile timeouts before retrying creation. Payment success is separate from booking acceptance; define compensation/refund handling before enabling live payments.

## Hotel-scoped access

Use users plus hotel memberships, allowing one user to belong to one or multiple hotels. Invitations and access grants specify the hotel and role.

Proposed roles:
- Platform administrator: all hotels, access grants and integrations.
- Niwadu onboarding employee: guided hotel creation and assigned onboarding drafts; no payment gateway or integration credentials/configuration access by default.
- Hotel manager: assigned hotel's listing, inventory, rates and bookings; staff invitations only when explicitly granted.
- Reservations staff: assigned hotel's bookings and permitted reservation actions.
- Inventory manager: assigned hotel's rates, allotments and restrictions.
- Viewer: read-only access to the assigned hotel.

Enforce hotel membership and action permissions in backend queries and commands, including exports, uploads, reports, background jobs and nested records. Browser filters are not authorization. Hotel staff must not grant platform roles or memberships in other hotels. Record access changes, inventory edits and booking actions in an audit log.

Hotel inventory permissions concern permitted day-to-day rates, allotments and stop-sales only. They do not grant PMS setup, ownership-mode changes or payment configuration access. PMS-owned fields must be clearly identified in the hotel UI and editable only where the approved integration supports the operation.

## Platform-owned payments

Use Niwadu's payment gateway. Merchant accounts, credentials, gateway selection, callback configuration and payment routing are platform settings, accessible only to explicitly authorized Niwadu staff. Never expose these settings in hotel onboarding or hotel account forms. Enforce the restriction server-side, including against forged updates containing payment fields. Hotel payout details, if required later, are separate from gateway ownership and do not authorize changing checkout payment routing.

## Guided hotel onboarding

Proposed steps for a Niwadu employee:
1. Hotel basics: name, property type, address/map location and contact details.
2. Listing: description, amenities and photographs.
3. Rooms: room types, occupancy and photographs, with a duplicate-room-type action.
4. Rates and availability: simple manual-entry controls when appropriate; for PMS-connected hotels show setup status and hand off technical configuration to authorized Niwadu staff.
5. Policies: check-in/out, cancellation and guest rules.
6. Hotel staff: invite users with explicit hotel and role assignments.
7. Review: preview the listing, identify missing requirements and publish when readiness checks pass.

Use plain language, sensible editable defaults, examples, progress indicators, inline validation, autosaved drafts and save/resume. Show only fields relevant to the chosen property and inventory setup. Do not require the employee to understand APIs, external IDs or payment configuration. A listing may remain a draft while integration setup is pending; do not silently substitute manual inventory or make it bookable before inventory readiness is verified. Publication permissions must be explicit.

## Public design fidelity

The live niwadu.com public website is the visual reference, including layout, typography, colors, imagery, spacing, navigation and responsive behavior. Inventory reference pages and interactive states across desktop and mobile before implementation. Preserve existing content and assets where available and record missing source assets rather than inventing a replacement style. Verify the rebuilt pages using matched-viewport screenshots and interaction checks. This fidelity requirement applies to the public site; the new backend onboarding follows the guided workflow above.

## SEO, AEO and GEO requirements

Render public listing and destination content in crawlable HTML. Preserve existing URLs wherever possible; prepare individual permanent redirects for changed URLs. Provide unique page titles/descriptions, canonical URLs, XML sitemaps, appropriate robots directives, internal links and social previews. Prevent filter/date/guest combinations from generating uncontrolled duplicate indexable pages, and keep account, checkout and administration pages out of search indexes.

Expose clear, accurate hotel and destination facts, amenities, location, policies and useful question-and-answer content. Use appropriate structured data matching visible content and verify supported schema/search features against current official documentation during implementation. Never invent ratings, reviews, facilities, prices or availability. State price context and content freshness where useful. Preserve the reference design while making semantic markup, accessible navigation, image optimization and performance improvements.

Treat AEO/GEO as making accurate information easy to retrieve, interpret and cite, not as a promise of rankings or AI citations. Measure crawlability, indexation, structured-data validity, performance and search/referral results. Decide crawler access deliberately without exposing private data.

## Initial acceptance criteria

- An administrator can create a hotel, invite its manager and revoke access.
- Hotel A staff cannot read or modify Hotel B records by changing URLs, IDs or API requests, including exports and media.
- A manual hotel can set nightly quantities and rates and receive a booking without overselling under concurrent requests.
- A Surge-connected hotel can map room/rate IDs and complete a booking against its API with duplicate-request protection.
- Stale sync, provider rejection and ambiguous timeouts appear as actionable backend states; no false confirmation is shown.
- A PMS-managed hotel can stop Niwadu sales locally without overwriting PMS stock.
- Hotel users cannot read credentials or mutate gateway/PMS settings through UI or direct API calls.
- An onboarding employee can save, resume and complete the wizard; test the flow with a first-time user without verbal coaching, and record any points needing help.
- A hotel cannot become bookable while its required inventory setup is incomplete.
- Public pages match the current site's reference screenshots at representative desktop and mobile sizes.
- Public page content is present in rendered HTML; canonical URLs, redirects, sitemap coverage and structured data pass verification before cutover.

## Evidence and unresolved decisions

Reviewed source: both Niwadu ZIP manifests/models/routes; Surge's api/v1 booking, availability and channel implementations, shared API wrapper and Channex adapter documentation/code. No runtime integration or existing test suite was executed during this discovery.

Surge supports search/quote/hold/confirm/cancel in its booking API. Its channel ingestion endpoint is intended for already-sold external reservations and can flag oversells; it is not interchangeable with the hold/confirm checkout path. Generic outgoing PMS webhooks are documented as unbuilt, while the Channex-specific synchronization implementation exists.

Still to decide: first-release feature scope; PAYable account/API access and settlement model; migration data/source asset availability; initial hotels/PMS deployment topology; provider access; whether hotel managers may invite staff by default. Public design direction and ownership of payment/PMS configuration are confirmed above.
