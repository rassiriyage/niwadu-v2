#!/bin/sh
# Local development context only. Never run as a Railway build/start hook.
set -eu
command=${1:-build}
repo=${2:-$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)}
if [ ! -f "$repo/apps/api/artisan" ] || [ ! -f "$repo/apps/web/package.json" ]; then
  echo "Expected an explicit Niwadu checkout with apps/api and apps/web." >&2
  exit 1
fi
export DO_NOT_TRACK=1
case "$command" in
  build)
    exec graft build --only-dir apps/web/src --only-dir apps/web/tests \
      --only-dir apps/api/app --only-dir apps/api/routes \
      --only-dir apps/api/database --only-dir apps/api/tests \
      --only-dir apps/api/bootstrap --only-dir apps/api/config "$repo"
    ;;
  check|map) exec graft "$command" "$repo" ;;
  *) echo "Usage: sh scripts/graft-context.sh [build|check|map] [checkout]" >&2; exit 2 ;;
esac
