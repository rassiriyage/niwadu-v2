# Setup and inputs

## Available

- GitHub repository: https://github.com/rassiriyage/niwadu-v2 (initially empty).
- Existing admin and supplier source archives, inspected as references.
- Local Surge PMS source at `/Users/rashmiassiriyage/frappe-bench-v16`.
- Public design reference: https://niwadu.com/.

## Needed for feature integration

| Input | Purpose | When needed |
|---|---|---|
| PAYable IPG sandbox account and account-specific API access | Checkout, callbacks and reconciliation | Before gateway integration tests |
| Public website source, original fonts/logos/media | Exact design and interaction fidelity | Before public UI recreation |
| Sanitized Niwadu schema/data sample and media mapping | Preserve listings, relationships and URLs | Before migration implementation |
| Isolated Surge test site, sample hotel/room/rate plan and narrow service account | Real adapter testing without operational bookings | Before PMS integration tests |
| Staging hosting account and hostname | Review full flows over HTTPS | Before shared acceptance testing |
| Transactional email provider and verified sender domain | Staff invitations and booking emails | Before external test delivery |
| Object storage and CDN | Property photographs and other uploads | Before staging uploads |
| Dedicated relational database, cache/queue and worker deployment | Inventory concurrency and integration retries | Before booking/integration staging |
| DNS and existing search/analytics access | URL migration and post-launch measurement | Before production cutover |

Keep test and production environments separate. Enter secrets in local ignored environment files or deployment secrets, not chat or repository files. The gateway remains owned by Niwadu. Hotel users must not configure payments or PMS integrations.

## Database decision

SQLite is temporary framework development storage. Choose the operational SQL engine after inspecting the legacy schema; verify locking and concurrent inventory sales against that exact engine. Any use of local MariaDB must use a separate Niwadu database/user and must not modify Frappe services or data.

## Framework references

- https://nextjs.org/docs/app/getting-started/installation
- https://laravel.com/framework/docs/13.x/installation

Production infrastructure and commercial service accounts have not been provisioned by this foundation slice.

## PAYable

The user selected PAYable and does not yet have a sandbox account. Arrange PAYable Internet Payment Gateway access in Niwadu's name and confirm the supported checkout API, sandbox credentials, callback requirements, supported currencies and refund access with PAYable. Do not create hotel-owned gateways.

Official developer references reviewed during setup:
- https://dev-developers.payable.lk/docs/ipg-direct-api/authentication
- https://dev-developers.payable.lk/docs/ipg-direct-api/webhooks
- https://dev-developers.payable.lk/docs/ipg-refund-api/authentication

Use fixtures until sandbox access is available. No PAYable SDK, credentials, API calls or simulated-success production path have been added. The eventual payment implementation must verify server notifications, invoice/amount/currency and booking state; a browser return alone is insufficient proof of payment.

## Foundation verification

Passed locally: frontend ESLint, TypeScript, production build and HTTP health check; backend framework smoke tests (2), Pint formatting, Composer validation; dependency audits reported no known vulnerabilities; staged secret scan found no leaks. Business functionality is not yet implemented.

GitHub publication is blocked: push returns HTTP 403, including when using the connected GitHub CLI credential. The account reports repository push permissions, so confirm the credential grants repository Contents write and Workflows write access (the commit adds a workflow). CI has been configured locally but has not run on GitHub.
