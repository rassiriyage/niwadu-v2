# Niwadu development context

Graft 0.19.0 is installed locally. This is development tooling, not an OTA runtime service or a Railway dependency. Structural graphs are generated from PHP/TypeScript in each checkout without a model provider. Repository-owned context below supplies business relationships that imports/call edges cannot establish.

## Read in this order

1. Current user request and `AGENTS.md` constraints.
2. [Requirements](../requirements.md), then the relevant section of [relationships](relationships.md).
3. [Team board](../../tasks/team.md) and the owning task's `tasks/components/*.md` report. Check the exact branch/commit and pending QA findings.
4. This checkout's Graft map and targeted source spans, then relevant tests.

Use [integration boundaries](integration-boundaries.md) before changing inventory, payments or PMS logic. Future features are described in [archive review](../feature-reference-review.md); proposals are not implemented capabilities.

## Commands

From the repository root:

```sh
sh scripts/graft-context.sh build
sh scripts/graft-context.sh check
sh scripts/graft-context.sh map
DO_NOT_TRACK=1 graft ask "HotelPolicy" --source .
DO_NOT_TRACK=1 graft skeleton apps/api/app/Policies/HotelPolicy.php .
DO_NOT_TRACK=1 graft callers HotelPolicy .
```

The helper accepts an explicit second argument for another Niwadu checkout. Example: `GRAFT_NO_GITIGNORE=1 GRAFT_NO_IGNORE=1 sh scripts/graft-context.sh build /Users/rashmiassiriyage/niwadu-worktrees/admin`. The flags avoid modifying worker ignore files; these existing local worktrees share a Git exclude entry for `/graft/`. Each checkout gets its own ignored `graft/` cache. Never share one generated graph among divergent branches. Source configuration files such as Next rewrites, CSS and project manifests still require direct inspection; the graph is not exhaustive context.

`graft init --agents agents --no-global --no-build` was used for project wiring; its generic AGENTS guidance was replaced with Niwadu-specific scope and verification rules. No global Codex hooks, global MCP configuration, other agents, remote Brain, model provider or daemon were configured. Current tasks can use the CLI immediately. A project-level instruction change is picked up naturally by future sessions; existing tasks receive an explicit handoff.

## Local graph viewer

A self-contained interactive export is available at `graft/viewer/index.html` in the coordinator checkout. It stays ignored with the graph and reflects that checkout only. Regenerate after source changes:

```sh
DO_NOT_TRACK=1 graft viz --export graft/viewer --title "Niwadu development context" --no-open .
```

## Maintenance and privacy

- Rebuild after branch changes or substantial edits; run check before relying on cached context. Queries may refresh automatically, but a clean graph does not mean tests passed.
- Commit curated context and wiring, never `graft/`. Graph caches and its extraction files are disposable.
- The build allowlist covers first-party app, route, migration, config and test source only. No home scan, PMS checkout, database, uploaded archives, reference photos, credentials, node_modules or vendor tree is requested.
- Commands disable telemetry with `DO_NOT_TRACK=1`. No provider key or deep pass is needed for this setup.
- Deep model-generated semantics remain off. The maintained relationship docs are the initial semantic context; do not confuse them with a completed Graft deep tier.
- On feature handoff, update the owning component report and links here only when a boundary changes. Record implementation status, exact commit and independent evidence separately. Do not copy private guest/hotel data into context.
- Recovery: delete only the checkout's disposable graph cache if corrupt, then rebuild. To remove wiring, remove the marked AGENTS section and helper/context docs after checking whether they remain useful; do not run a global uninstall blindly.

Official reference: https://github.com/trailhq/Graft . Installed CLI help/source was checked for actual0.19.0 behavior. No performance-saving claims have been measured for Niwadu.
