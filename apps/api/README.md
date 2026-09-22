# Niwadu API

Laravel backend for the Niwadu OTA. See ../../README.md for local setup and verification.

Current routes are framework health `/up` and service identity `/`. Hotel authorization, onboarding, inventory, PMS integrations and payments are not implemented yet.

The local SQLite file and application key are generated per checkout and excluded from git. Existing Frappe data is not used. Laravel Boost is a development dependency supplied for the generated project guidelines; no global MCP configuration was installed.
