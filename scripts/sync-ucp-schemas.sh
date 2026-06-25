#!/usr/bin/env bash

set -euo pipefail

TARGET_VERSION="${1:-2026-04-08}"
UCP_SOURCE_DIR="${2:-${UCP_SOURCE_DIR:-}}"

if [[ -z "${UCP_SOURCE_DIR}" ]]; then
  cat >&2 <<'USAGE'
Usage:
  scripts/sync-ucp-schemas.sh <version> <path-to-ucp-source>

Example:
  git clone --depth 1 --branch v2026-04-08 https://github.com/Universal-Commerce-Protocol/ucp.git var/ucp-v2026-04-08
  docker compose run --rm php bash scripts/sync-ucp-schemas.sh 2026-04-08 var/ucp-v2026-04-08

You may also pass UCP_SOURCE_DIR instead of the second argument.
USAGE
  exit 1
fi

php tools/sync-ucp-schemas.php "${TARGET_VERSION}" "${UCP_SOURCE_DIR}"
