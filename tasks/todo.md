# Foundation tasks

- [x] Clone the repository and create a setup branch.
- [x] Record confirmed requirements and implementation boundaries.
- [x] Scaffold Next.js with TypeScript; verify lint, type check and production build.
- [x] Scaffold Laravel; verify framework tests and isolated local database setup.
- [x] Document commands and service prerequisites.
- [x] Review generated files for credentials and unintended dependencies.
- [x] Commit the verified frontend/API/docs foundation locally.
- [x] Publish the verified foundation to GitHub main using the updated credentials.

## Next slice (not implemented)

- [ ] Specify and implement hotel memberships and platform/hotel permissions.
  - Acceptance: hotel A cannot read/write/export hotel B records; hotel users cannot change payment/PMS settings.
  - Verification: API authorization tests using separate hotel users and forged record IDs.
  - Depends on: framework foundation.
- [ ] Specify guided onboarding and publication checks.
  - Acceptance: employee can save/resume a draft and identify all required steps; no publication with missing inventory readiness.
  - Verification: persistence tests and first-time-user walkthrough.
  - Depends on: memberships and authorization.
