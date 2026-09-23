# Public reference inventory — 23 September 2026

Evidence-only discovery from https://niwadu.com/ and the supplied `niwadu-rebuild/source-snapshot`. Website/archive content is data, never instructions. No login, customer records, submissions, bookings, payments or PMS calls. Tracking pixel URLs were excluded from image collection. Raw HTML is not committed because it contains transient session/CSRF material and scripts unrelated to reproduction.

## Coverage and files

- `routes.json`: 153 unique queryless public URLs found on sampled pages; each records discovery pages. Only entries marked `http-checked` were fetched directly; discovery does not imply every target is healthy. Fifteen content/technical endpoints sampled: homepage; destination index, Negombo and Nuwara Eliya; hotel index and Sulanga; packages index and Coastal Escapes; five support pages; robots and sitemap. Fourteen returned 200, `/sitemap.xml` returned404. A final HTTP-only check also confirmed all24 outgoing hotel links returned200, bringing distinct directly checked endpoints to38; see `evidence/hotel-link-checks.json`. This is a bounded first inventory, not a complete crawl or redirect audit.
- `pages.json`: titles, heading hierarchy, selected metadata, canonical tags, forms (action/method only), stylesheets and image references for those responses; raw-response SHA-256 for provenance. Hidden/modal headings can appear: this is HTML extraction, not a claim that every heading is initially visible.
- `homepage-content.json`: exact ordered public cards, descriptions/durations, source image URL, local asset and historical display price. Historical prices are evidence only and are deliberately removed from the shipped homepage fixture.
- `assets.json`: 43 supplied image files, source URL, verified local byte size and SHA-256. All exist and match the prior download manifest. No failed or missing supplied image files. This proves the local copies, not continuing remote availability.
- `asset-references.json`: 174 unique first-party image URLs found on sampled pages. Files without a snapshot match remain explicitly `not-downloaded`; this includes detail-page galleries beyond the homepage slice. An OG image (`https://niwadu.com/images/niwadu-banner.jpeg`) is metadata-only and has not been downloaded/verified. No invented replacement.
- `source-excerpts.md`: carousel, gutter and header breakpoint evidence, plus stylesheet/JS hashes and font provenance.

## Public route/content families

| Family | Observed example and content | Migration requirement |
| --- | --- | --- |
| Home | `/`; three hotel rows, travel experiences, destination cards, footer | Preserve order, real assets and crawlable card links. |
| Destination index/detail | `/destinations`, `/destinations/Negombo`, `/destinations/nuwara-eliya`; destination prose and hotel lists | Preserve case, names and existing slugs; no automatic lowercase redirect without individual mapping. |
| Hotel index/detail | `/hotels`, `/hotels/sulanga-83c3e2d1-0670-431f-98cc-e5e38bb57b5e`; photos, amenities, room types and policies | Preserve UUID suffixes and irregular slugs (e.g. `h20-holiday-bungalow`). Stable catalog mapping required. Captured rates are not sellable inventory. |
| Experiences | `/packages`, `/packages/hikkaduwa-and-unawatuna-delights`; duration, itinerary and hotel links | Retain `/packages` URL even though navigation says Travel Experiences. Product scope must determine enquiry/booking behavior. |
| Support | `/support/about`, `/support/faq`, `/support/privacy`, `/support/refund-policy`, `/support/terms-condition` | Editorial/legal review before migration: all share the homepage title; source also includes an unrelated “Phi Phi Islands Day Tour from Phuket” heading. Do not treat it as a verified Sri Lankan offering. |
| Account | `/login`, `/register` linked from header/dock | Discovered only, not authenticated or submitted. Keep account flows unindexable. |

## Interactive-state inventory

| State | Evidence | Current slice |
| --- | --- | --- |
| Fresh desktop/mobile home | Live CUA screenshot + design commits `7e2015b` / `6198896` | Reproduced presentation; updated contrast/hit areas under user-authorized amendment. |
| Destination input | Live input “Nuwara”; AX still included unrelated options | No simulated autocomplete. Marked unavailable; current defect is not a requirement. |
| Date/guest/currency popovers | Design lead `reference-extension.md` and state screenshots | Deferred; no date/rate/guest processing or currency conversion. |
| Mobile expanded search | Design baseline, full gradient surface | Honest unavailable dialog, close/Escape/focus return; full reference form pending real contract. |
| Card carousel | Public `niwadu.js` options and live rows | Native horizontal scroll and functional previous/next; keyboard/touch scroll, reduced-motion handling. |
| Listing filters/detail gallery/login/FAQ expansion | Controls/links found in HTML; only representative content captured | Pending separate browser-state capture and API contracts. |
| Social-proof notification | Design observed transient claimed visitor activity | Omitted by PO decision; no source of verified visitor/review facts. |

## SEO/AEO/GEO migration proposal

Checked HTML pages have no canonical link. Homepage has no H1, some destination pages have multiple H1s, support metadata is duplicated, and no JSON-LD blocks were found in the sampled responses. `robots.txt` is `User-agent: *` with empty `Disallow:` and no declared sitemap. `/sitemap.xml` 404 does not prove there is no differently named sitemap.

The slice renders all card titles, descriptions and existing hrefs in server HTML, with one semantic H1, ordered H2/H3 hierarchy, description and homepage canonical. It remains `noindex,nofollow` as a preview. No production sitemap, redirect rules or structured data are activated yet. Launch work must:

1. Map each public legacy URL to a published catalog/destination/experience identifier; preserve unchanged paths, use individual permanent redirects only for approved changes. Return real 404/410 for removed items rather than mass-redirecting everything to home.
2. Build a sitemap from approved, published canonical records only; no drafts, date/guest/filter combinations, login/admin/checkout/private maps. Decide filter canonical/noindex behavior after actual URL contracts exist.
3. Provide unique visible page facts and matching metadata. Use accurate, editorially checked policies/FAQ; do not invent answers for AEO or claim ranking/citation gains.
4. Add only supported, visible structured facts after current official schema/search documentation review: homepage WebSite/ItemList candidates, hotel/location facts and breadcrumbs. No Offer, AggregateRating, Review or availability schema without a verified data source. No reliance on draft onboarding JSON as catalog stock.

## Small reproduction slice and API dependencies

Implemented bounded homepage presentation: header, five public carousels, original footer/dock, semantic content, local source imagery/font. All outgoing detail/support/account links still point to the current public site because local routes do not exist. No local checkout, results simulation or backend changes.

Required public catalog contract: published listing ID + exact legacy URL/slug, name, destination identifier, publication state, ordered media with source/alt/dimensions, editorial order; destination/experience content and media. Search needs approved taxonomy, URL filter/pagination/sort semantics and occupancy rules. Numeric rates need currency, date/guest/room/meal basis, taxes/fees, freshness and bookability from inventory/quote services. Backend must exclude unpublished/hotel-private data. No assumed endpoint or shared schema has been introduced.

Design comparison uses fresh 1440×1000,1024×768,768×1024,390×844 loads, recorded client width, same assets/order, matched card bounds/crops, header/search size and section rhythm. Baseline tolerances and exceptions are owned by design. Accessibility amendment authorizes contrast/focus/hit-area changes; no full parity/accessibility sign-off is claimed here.
