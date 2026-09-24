# Working local trip planner

Reference: supplied niwadu-main.zip, app/plan/page.tsx and components/TripPlanner.tsx. Preserve its /plan route, white/ink/pink shell, numbered pill rail, bordered stop cards and desktop 400px summary column with stacked mobile layout. Functional adaptation approved by PO/design: destinations → order/nights → review. Stays and enquiry steps await backend integration; no dead steps or quote promises.

Destination names, slugs and coordinates are a geographic subset of the supplied data/seed.json (89 destinations). No hotel counts, availability, pricing, or private/media data is imported. Map lines indicate stop order only, not road paths or estimates. No archive scripts, migrations or services were executed.

Local state: niwadu.trip-draft.v1, JSON {version:1,stops:[{slug,nights}]}. Validated known unique slugs, max20 stops, integer nights1–14 and 10KB read bound. Drafts restore on editor mount and save on edits; malformed or inaccessible storage presents recovery without preventing editing. Storage failure is visible; itinerary can be downloaded as plain text. No account persistence, transmission, booking or payment. Existing nw-plan reference drafts are not silently imported. Start over requires an explicit inline clear action.

Homepage header/menu, promotional card and footer planner entries link internally to /plan. Other public features retain their current state until implemented; this slice is not full public delivery. Image optimizer, carousel gesture and admin/auth behavior preserved.

Verification: production build/TypeScript and lint; local browser scenarios for create/edit/reorder/remove, focus, nights bounds, refresh, blocked storage, malformed draft, download, clear/cancel and homepage navigation. Initial pre-implementation test could not reach the stopped local server and is not claimed as a behavioral red test. Dedicated playwright.public.config.ts runs public scenarios against a separately started web server, with no API seeder. Independent functional QA required on exact candidate before publication; final integrated UI/UX review deferred until public journeys are complete per user direction.
