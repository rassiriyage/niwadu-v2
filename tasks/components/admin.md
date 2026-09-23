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
