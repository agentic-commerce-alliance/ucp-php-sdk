#!/usr/bin/env bash

set -euo pipefail

# Thin wrapper around tools/sync-ucp-schemas.php, which owns argument validation. This exists
# to document the one step it cannot do for you: the upstream checkout. Nothing here fetches,
# pins or checksums the spec -- the operator chooses the tag, and `--verify` is what proves the
# committed generated set still matches whatever was pinned from it.

if [[ $# -eq 0 ]]; then
  cat >&2 <<'USAGE'
Usage:
  scripts/sync-ucp-schemas.sh <version> [<path-to-ucp-source>]   sync from an upstream checkout
  scripts/sync-ucp-schemas.sh --verify [<version>]               regenerate from the pinned copy
                                                                 and diff; all versions if none given

Sync example:
  git clone --depth 1 --branch v2026-08-25 https://github.com/Universal-Commerce-Protocol/ucp.git var/ucp-v2026-08-25
  docker compose run --rm php bash scripts/sync-ucp-schemas.sh 2026-08-25 var/ucp-v2026-08-25

The version is required and no longer defaults: omitting it used to regenerate a version the
operator had not asked for. You may pass UCP_SOURCE_DIR instead of the second argument.

Verify runs offline and is part of `composer qa`, so a hand-edited generated schema or an
upstream retag that changed the pinned inputs fails the build.
USAGE
  exit 1
fi

php tools/sync-ucp-schemas.php "$@"
