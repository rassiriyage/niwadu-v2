# Niwadu v2

Read docs/requirements.md and tasks/plan.md before implementation.

- Niwadu is an independent OTA; Surge/Frappe is one inventory provider.
- Only authorized Niwadu staff configure payments, PMS connections and mappings.
- Enforce hotel membership and action permissions server-side, including exports.
- Onboarding is a simple autosaving step-by-step wizard for Niwadu employees.
- Match the existing niwadu.com public design. Do not invent a redesign or demo listings.
- Preserve URLs and crawlable content. Structured data must reflect visible facts.
- Never silently turn disconnected PMS stock into manual inventory.
- Keep secrets out of git and browser bundles. Use .env.example placeholders.
- Never use the existing Frappe database for Niwadu or run migrations against it.
- Do not send real payments, bookings, invitations or live PMS updates during development.
- Verify each feature with focused checks. Never label stubs as working integrations.

Use apps/web for Next.js and apps/api for Laravel. Keep domain operations in the API.
