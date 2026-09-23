# Integration boundaries and evidence

## Hotel and provider relationships

A hotel can have PMS connection configuration managed only by authorized Niwadu staff. A room inventory pool maps to one active connection/property/external room; a local rate plan maps to an external rate plus board/pricing tuple. Plans share room stock. External rate IDs are not assumed globally unique. Hotel staff may manage only allowed hotel operations, never credentials, merchant routing or PMS ownership changes.

Manual stock is a deliberate ownership mode. Stale/disconnected PMS stock never becomes manual automatically. Booking-time availability must cover each stay night and restrictions; arbitrary freshness TTLs are not proof of provider guarantees.

## Surge channel manager

User requires a channel-manager connection. Source review found generic channel ingestion can confirm oversells and does not atomically consume the booking hold. Direct booking API functionality is not a silently acceptable substitute. The channel extension proposal requires priced holds, atomic consume, scoped idempotency/reconciliation, accepted policy binding and cancellation/release fencing. These remain provider proposal/sandbox work, not an implemented OTA contract. Do not index or modify the live Frappe database.

## Quotes and money

Nightly price rows: `stay_date`, integer `base_minor`, `tax_minor`, `fee_minor`, strict `mandatory_charges_complete: true`. Unknown charges are not zero. Accepted policies, currency and complete stay prices travel with the quote. Synthetic calculator also requires per-night inventory/restriction conditions; pure arithmetic does not verify current stock. Inventory owns atomic hold/commit/release, bookings own intent and reconciliation.

## PAYable

Niwadu owns gateway configuration. Sandbox account and complete current signing/idempotency/reconciliation evidence remain prerequisites. A payment notification and provider booking acceptance are distinct events. Ambiguous provider outcomes require reconciliation; late payment must not revive expired inventory. Hotel payout details never grant gateway configuration rights.

## Evidence hierarchy

Current source plus meaningful tests establish implementation. Signed-off exact commits establish bounded QA/design acceptance. Provider documentation and sandbox observations establish external capability. Generated graph edges, archive examples and planned diagrams establish none of those on their own.
