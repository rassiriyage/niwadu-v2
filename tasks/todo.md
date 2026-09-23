# Foundation tasks

- [x] Clone the repository and create a setup branch.
- [x] Record confirmed requirements and implementation boundaries.
- [x] Scaffold Next.js with TypeScript; verify lint, type check and production build.
- [x] Scaffold Laravel; verify framework tests and isolated local database setup.
- [x] Document commands and service prerequisites.
- [x] Review generated files for credentials and unintended dependencies.
- [x] Commit the verified frontend/API/docs foundation locally.
- [x] Publish the verified foundation to GitHub main using the updated credentials.

## Hotel access slice

- [x] Implement hotel memberships, session authentication and profile/staff permissions.
  - Acceptance: hotel A cannot read/write hotel B profiles or staff rosters; profile writes reject payment/PMS settings. Export and inventory permissions must be tested when those endpoints exist.
  - Verification: API authorization tests using separate hotel users and forged record IDs.
  - Depends on: framework foundation.
- [x] Implement guided onboarding drafts and explicit publication blockers.
  - Acceptance: employee can save/resume a draft and identify all required steps; no publication with missing inventory readiness.
  - Verification: API persistence/conflict/access tests and a browser walkthrough using an onboarding employee. A human first-time usability session remains before launch.
  - Depends on: memberships and authorization.

## Next slice

- [ ] Turn room drafts into a catalog with stable room IDs and structured photo assignments.
- [ ] Implement dated manual inventory and rate plans against a dedicated transactional database, with oversell/concurrency tests.

## Additional discovery and traveller features

- [x] Review the supplied archive for classification, filters/sort, planner and travel map behavior; record gaps and proposed improvements.
- [ ] Agree classification vocabulary across catalog, onboarding and public search; verify scoped edits and independent theme/type/star fields.
- [ ] Deliver URL-driven discovery filters and explicit sorting; verify combined filters, pagination, Back/refresh and truthful dated prices. Depends on catalog and real inventory for availability.
- [ ] Resolve planner scope, then deliver save/share itinerary slice; verify mobile reorder, restore, dates and unknown costs.
- [ ] Resolve map privacy choice, then deliver account-owned travel progress; verify isolation, persistence and explicit sharing/import.
- [ ] Checkpoint: QA and design validate each implemented feature before integration; retain existing security/recovery release blockers.

## Graft context integration

- [x] Inspect installed Graft and dry-run project wiring.
- [x] Add scoped build helper and curated domain/integration relationships.
- [x] Verify graph freshness, source scope and representative queries across coordinator and eight worktrees; hand off worktree usage.
