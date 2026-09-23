# Administration component — first deliverable

Status: verified locally, ready for independent QA; not pushed, merged or deployed.
Branch: `team/admin`, based on `bad09b5`. The implementation and this report are in the commit titled `feat: provision onboarding employees without administrator powers` (resolve with `git log -1 team/admin`).

## Delivered behavior

- Added `niwadu:create-onboarding-employee {email} {--name=}` with the existing administrator command's hidden password, email normalization, name/email/password validation and password hashing behavior.
- Hard-coded the existing `onboarding` role. No role option, public registration, default credentials, hotel membership or email delivery.
- Existing accounts (hotel user, employee or administrator) are rejected without changes. The administrator command is unchanged.
- Root README documents operator setup, secure credential handoff and employee draft scope. No schema/API contract changed.

## Evidence

- New tests failed first because the command did not exist.
- Focused suite: 10 tests / 92 assertions passed, covering validation, normalization, hashing, no mail/notification, existing-account preservation, own-draft create/save/resume, cross-hotel and published-hotel denial, and rejection of administrator/payment/PMS injection.
- Full API suite: 36 tests / 241 assertions passed using PHP 8.4 and `composer test` with `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= MAIL_MAILER=log`.
- `vendor/bin/pint --dirty --format agent` passed. `git diff --check` passed.
- Composer installed locked dependencies inside this checkout (Laravel 13.33.0). A fresh local key was generated from `.env.example`; no secrets were copied or committed.
- No browser/UI changes or browser port use. No live invitations, bookings, payments or PMS writes.

## Boundaries and dependency requests

Payment/PMS configuration endpoints do not exist yet: coverage proves current profile/onboarding field rejection and no administrator role, not future integration authorization. QA should independently review this commit together with inherited access/onboarding rules. Product owner should forward later QA/design findings before dispatching the next bounded increment. No shared schema decision or external account is required for this slice.

## QA-01 password-reset session revocation

- Enabled Laravel 13.33 `auth.session` across the versioned API, including `/session`. This installed framework already seeds `password_hash_web` in `SessionGuard::login`; no custom session schema or deletion loop is needed. Password reset continues rotating the remember token.
- API guest redirects return no redirect target, avoiding an undefined web login route when Laravel rejects a stale hash. Workspace opens sign-in after a 401 during session introspection.
- Five focused feature tests cover immediate login-then-reset revocation at both `/session` and `/hotels`, new versus old password, reset-link reuse denial, unaffected unrelated users, obsolete remember cookies and untrusted remember input. Normal login does not support remember-me.
- Red evidence: two tests returned 200 before middleware; green: full 41 API tests / 277 assertions passed; Pint passed. QA's independent browser reproduction was adapted to accept the first stale request as 401 and then guest state; it passed with database sessions, separate browser contexts, log-only mail and ports 3203/8203. New password succeeds, old password and reused link fail, unrelated employee remains authenticated.
- Existing sessions created by this baseline have the login fingerprint. Any future authentication path must continue using Laravel's session guard login mechanism. No live accounts or PMS databases were used.

## QA-03 explicit sign-out recovery

- If draft flushing fails, a native modal offers Keep editing (initial keyboard focus) or explicit Discard unsaved changes and sign out. Escape keeps editing. The discard action calls backend logout without repeating the failed save; a401 means the session is already gone. Failed logout keeps the decision visible with an error.
- Regression first failed because no decision existed. Chromium checks passed for422 validation,409 conflict and503 server failures, including keep-editing value preservation, explicit discard, guest session and401 on private hotel data. Type checking and lint passed. No P2 layout redesign included.
