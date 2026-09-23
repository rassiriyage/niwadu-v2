# Niwadu v2 implementation plan

The user authorized starting the build after defining the architecture and product requirements. See docs/requirements.md for the confirmed scope and remaining decisions.

## Completed slice: project foundation

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

## Completed slice: hotel access

Use Laravel session authentication behind the frontend's same-origin API proxy, with CSRF protection, login throttling and private responses. No public registration or client-editable platform role. A console command bootstraps the platform administrator with a hidden password prompt.

Administrators can create hotels and assign/revoke hotel memberships. Onboarding employees work only on their own drafts. Hotel managers may edit their assigned hotel's profile; reservations/inventory/viewer roles can read the profile but do not acquire unrelated write permissions. Hotel managers cannot grant staff roles in this initial version. New staff accounts receive password-setup links through Laravel's broker; local mail remains log-only. Existing account passwords are never replaced when assigning a hotel.

Expose versioned endpoints for session login/logout, hotels and hotel staff. Allowlist profile fields and reject payment/PMS settings, publication status and role injection. Record hotel creation/profile changes and membership grants/revocations in an audit table. Test cross-hotel IDs, stale/revoked memberships, invalid roles, password setup, session/CSRF behavior and employee scope. The public website and full seven-step onboarding remain subsequent slices.

Validation: 20 API tests (105 assertions), five Chromium browser scenarios, frontend lint/type checking and production build. Browser fixtures use a separate SQLite database. Next slice: guided autosaved hotel onboarding and publication readiness; no live integrations are enabled.

## Current slice: guided onboarding drafts

Keep the original seven steps: basics, listing, rooms, rates/availability, policies, staff and review. Collect draft room types and indicative rates without creating sellable inventory. Save progress and partial input automatically; a version check rejects stale edits instead of overwriting another employee's work. Niwadu administrators and an employee's own drafts can use onboarding; hotel staff cannot change it. Review shows missing content and explicitly blocks publication until rooms/rates are promoted to verified inventory and booking/payment setup exists. Inventory selection is a setup request, never a PMS connection configuration or ownership switch. Verify persistence, conflict handling, field allowlists, cross-hotel denial and a browser walkthrough including refresh/resume.
