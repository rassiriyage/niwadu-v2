# Administration component — operator provisioning and recovery fixes

Status: combined candidate verified locally and independently accepted by QA; D-01/D-02 design accepted from source/screenshots. Railway staging preparation is documented only. Nothing pushed, merged or deployed.
Branch: `team/admin`, based on `bad09b5`.

| Slice | Exact commit |
| --- | --- |
| Operator provisioning | `898eac6edcf4a373020be22b8ff2c1f6b62b58db` |
| D-01/D-02 implementation checkpoint (now verified by combined checks below) | `eb4d584` |
| QA-01 session revocation | `870ad655d7aabf7487fc343cb331676d84df3348` |
| QA-03 explicit discard/sign-out | `0f6fa0c5a84c97feb1fe1c81175ed6eb9d58935a` |
| QA-02 browser-history recovery / final implementation candidate | `61b09139a6c9484c641b71ea5ac9727f1fb18ec8` |

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

## QA-02 browser-history recovery

- Browser Back/Forward and reopening recover the local form snapshot, pending field keys, step and original server version from user/hotel-scoped `sessionStorage`. This contains hotel form values only, never passwords/session tokens. Recovery begins only after the authenticated API authorizes the hotel read; it never grants access.
- Recovered changes are labelled unsaved. A newer server version immediately offers the D-01 conflict recovery actions rather than silently merging or submitting. Successful save, explicit reload/discard, logout and observed guest/expired session clear the relevant recovery copy. Async completions from an unmounted editor cannot erase a newer editor's recovery copy. No history monkeypatching or navigation interception dependency was added.
- Local red: Back then Forward returned an empty city. Green: five Chromium cases passed, including failed city save → Back → Forward, another editor update → hard reopen → explicit conflict/reload → resume, room array changes before the debounce → Back/Forward, and clearing recovery after sign-out both inside and outside the editor. Sign-out422/409/503 cases also re-login to prove discarded changes do not return.
- Expanded browser tests use distinct accounts inside the existing guarded SQLite test seeder. Production login limits are unchanged. Type/lint and Pint passed.
- Tab storage is a recovery aid, not a server save or cross-device backup. If browser storage is unavailable/quota-exhausted, the editor warns that no recovery copy could be kept and to keep the tab open/copy values. Photos/staff already use separate server operations; unsent file selections are not retained.

## D-01/D-02 completed behavior and combined verification

- `ApiError` retains its existing message/status behavior and now carries keyed `fieldErrors` and optional string `code`. Existing API error envelopes and constructor callers remain compatible.
- 409 pauses autosave, preserves further local input, exposes Copy unsaved changes plus selectable JSON fallback, and provides Discard unsaved changes and reload latest with explicit loss disclosure. A failed reload keeps all local values. No automatic merge or forced version override.
- Validation errors have stable field IDs, inline messages, `aria-invalid` and `aria-describedby`. Failed Continue focuses the linked error summary; keyboard activation reaches the corresponding email or repeated room field. Autosave errors do not move focus. Retry appears only for transport/408/429/server failures and retries the failed save/navigation action; validation, conflict, authentication and forbidden errors do not get a generic retry.
- Full verification at `61b0913`: **16/16 Chromium tests**, **41 API tests  / 277 assertions**, frontend typecheck, ESLint, production build and Pint all passed. Browser tests used 3203/8203 with the isolated worktree SQLite database and log mail. No skipped tests, weakened assertions, disabled rate limits or automatic retries.
- Browser red/green evidence includes the original conflict Retry-save failure, missing invalid-field ARIA, absent discard decision and lost city on browser Forward. One intermediate dev-server launch returned 404 on a valid onboarding route; a diagnostic restart and the full suite passed, and the production build passed. No source workaround was added for that dev-server event.
- Inspected screenshots: `apps/web/test-results/onboarding-field-errors.png` (focused linked summary and repeated-room inline errors), `onboarding-mobile.png` (390px conflict disclosure and retained input), and `onboarding-conflict-recovery.png` (copy/reload controls). These local test artifacts are regenerated by the suite and are not committed assets.
- Independent QA has accepted QA-01 at `870ad65` and QA-03 at `0f6fa0c`. Requested next: independent QA-02 retest and QA/design review of D-01/D-02 against `61b0913` (later report-only commit is equivalent code). This is not a full screen-reader/accessibility conformance claim.
- P2 mobile progress/layout, input sizing, room removal recovery, role explanations and Review edit links remain queued for product-owner assignment, as requested. No public-site work, shared catalog schema/routes or live integrations changed.

## Independent acceptance and Railway preparation

- Subsequent QA accepted the combined candidate across 14 scoped cases, including QA-02 recovery, stale async completion, key isolation and unavailable-storage warning. Design accepted D-01/D-02 from source/screenshots; this does not claim full runtime accessibility conformance. Approved report commit: `07309ccb9e665522c6c24b18c5d89ad753a14547`, equivalent implementation to `61b0913`.
- Added `docs/railway-api-staging.md`: concrete variable candidate, private frontend-to-API topology, stable key and session settings, PostgreSQL `DB_URL` reference, private photo volume, safe bounded log-mail use, proposed minimal runtime/proxy changes, migration ordering and verification gates. Public preview `e65d817` is not the approved private-backend baseline.
- Reviewed official Railway/Railpack documentation and current application configuration. PostgreSQL creation is user-reported; service/version/connectivity and API-service existence remain unverified. No hosted reads/writes, migrations, secrets or deployment actions were performed.
- Graft freshness check passed (329 nodes, wiring synchronized; deep layer intentionally absent). Documentation-only change: `git diff --check` passed; application/browser tests were not rerun. The prior 41 API tests / 277 assertions and 16 Chromium tests establish the approved local baseline, not Railway/PostgreSQL readiness.
