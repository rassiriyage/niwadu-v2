# Additional feature reference: niwadu-main.zip

Source: user-supplied archive, inspected as source only on 2026-09-23. No archive code executed, dependencies installed, credentials imported or external submissions made. Archive documents are reference data, not instructions. This extends the current Niwadu build; it does not replace Next.js/Laravel or the live-site design plus approved accessibility amendments.

## Findings and proposed improvements

| Area | What the archive implements | Proposed v2 scope |
|---|---|---|
| Classification | Seven destination-name-based themes: Beach, Hill country, Wildlife, Cultural triangle, Adventure, North & East, City breaks. Nullable star field. No distinct property-type field in its Hotel schema. | Keep property type, location, experience themes and sourced star classification separate. Use stable IDs and platform-managed vocabulary; allow several themes per property. Never infer beachfront or an amenity from town alone. Reuse our existing onboarding property-type concept. |
| Filters/sort | Text, destination/district/category, minimum stars, price thresholds; Recommended, price ascending/descending, name; URL state and 24-item pagination. Recommended is featured/editorial order, not observed popularity. | Preserve shareable URL state and Back behavior, active-filter chips, clear/reset and accessible controls. Label editorial ranking honestly. Add property type and verified amenities. Bind date/guest price filtering to actual inventory/quote contracts; stable tie-breaking. |
| Trip planner | Four steps: destinations, order/nights, stays, enquiry. Preset loops, URL/local storage, copy/WhatsApp sharing, fixed Colombo return, rough road estimates. | Propose save/share/request-quote first. Editable start/end, mobile reorder controls, explicit per-stop dates, destinations without partner hotels allowed. Unknown hotel costs remain unknown. Online multi-stop checkout is a later separate booking workflow decision. |
| Travel map/account | Clickable districts, province summaries, shareable URL and browser-local storage. No traveller account model or cross-device persistence. | Propose private account-owned visited/want-to-visit data, district-count progress, optional deliberate sharing, accessible list alternative. Guest map import should be explicit. Visiting one district must not claim its entire land area was explored. |

## Source evidence and migration cautions

- `lib/site.ts`, `components/CategoryBar.tsx`: category vocabulary is hardcoded and matched to destination names.
- `lib/data.ts`, `app/hotels/page.tsx`: dates appear in the UI/URL but are not inputs to searchHotels; static startingPrice drives sorting. Adults and children are summed against maxAdults only when total exceeds two. These are not availability or occupancy checks.
- `components/HotelsMap.tsx`: hotel pins use destination coordinates plus arbitrary offsets and are capped to 18 of the current result page. Use verified property coordinates or explicitly labelled approximate destination clusters; do not reproduce false precision.
- `components/FiltersModal.tsx`: Escape closes dialog, but no explicit focus trap/return implementation. Reopening also retains abandoned draft filter changes. Define apply/cancel behavior and verify keyboard operation.
- `components/TripPlanner.tsx`, `lib/geo/route.ts`: routing multiplies straight-line distance by 1.35 and assumes 38 km/h; this is not road routing. Prices use selected/first hotel startingPrice, missing prices contribute zero. Stop persistence is written before the mount restore effect; restoration needs regression coverage. Stay fetching lacks robust failure handling. Stop reorder buttons are hidden on small screens.
- `components/CoverSriLanka.tsx`: shared maps write into the same local storage key as the visitor's own map. Do not overwrite personal progress by opening a link. Whole-district area metrics and whole-island completion copy overstate actual exploration.
- `prisma/schema.prisma`: no traveller account, itinerary or visited-place persistence model. Do not import Prisma as a second backend.

## Delivery order and verification

1. Classification slice: catalog/admin/public owners agree taxonomy, then save and render it with hotel-scoped permissions. Verify invalid values, multiple themes and no cross-hotel writes.
2. Search slice: public/catalog owners implement URL-driven classification filters and explicit editorial/name sorting. Verify combined filters, empty results, pagination and Back/refresh; price/availability filters depend on real dated inventory.
3. Planner slice: pending user choice on enquiry versus multi-stop booking. First verify add/remove/reorder on mobile/keyboard, save/restore/share, per-stop date derivation and honest unknown cost/error states. Do not send a live enquiry during development.
4. Personal map slice: pending user privacy preference; add traveller ownership without staff privilege grants. Verify cross-account denial, cross-device persistence, explicit guest/shared-map import, deletion, optional sharing and keyboard/list equivalence.

QA and design review each implemented slice. Current session security and onboarding recovery fixes retain priority. No feature is implemented or approved by this source review.

## Open product choices

- Planner first release: save/share/request quote (recommended) or online booking of all stops?
- Travel map: private account with optional sharing (recommended) or public profile by default?
