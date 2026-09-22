# Niwadu v2 implementation plan

The user authorized starting the build after defining the architecture and product requirements. See docs/requirements.md for the confirmed scope and remaining decisions.

## Current slice: project foundation

Create the Next.js/TypeScript frontend and Laravel API in one repository. Verify frontend build/type/lint checks and backend tests. Document local setup and service prerequisites. This slice does not implement or deploy the public site, authentication, payments or PMS booking.

Start locally with isolated SQLite for framework smoke checks. Before implementing booking inventory, choose and provision a dedicated transactional SQL database and test concurrency against that engine. Do not reuse the running PMS database. Queue/cache services can be introduced when the first integration needs them.

## Build order

1. Foundation and repeatable development commands.
2. Hotel memberships and server-side authorization, verified with cross-hotel denial tests.
3. Guided hotel onboarding with autosaved drafts and explicit publication readiness.
4. Manual rooms, rates and inventory with transaction/concurrency verification.
5. Surge provider mapping and sandbox quote/hold/confirm/cancel flow.
6. Platform-owned payment integration and failure/reconciliation handling.
7. Reproduce public Niwadu routes, layouts and assets, and connect search/checkout.
8. SEO migration, browser/accessibility verification, staging and launch checks.

For steps 2–8, break each into implementation-sized tasks before starting it. Scope payment behavior only after inspecting the real gateway's API. Confirm provider access before claiming another PMS or Channex integration works.

## Risks

- Live public-site source and data have not been supplied: capture visual references and obtain assets/source for exact fidelity.
- Existing Niwadu schema/data migration has not been tested.
- PMS-managed availability may become stale: booking-time checks and reconciliation are mandatory.
- Payment success and booking acceptance are separate states; handle compensation explicitly.

## First milestone

An authorized Niwadu employee creates a hotel, invites its manager in a test environment, adds manual inventory and completes a test booking; a second hotel proves access isolation. Then verify the equivalent booking through a sandbox Surge connection.
