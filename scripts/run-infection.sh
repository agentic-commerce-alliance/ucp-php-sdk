#!/bin/sh

set -eu

REPO_ROOT="$(git rev-parse --show-toplevel)"
git config --global --add safe.directory "${REPO_ROOT}" >/dev/null 2>&1 || true

exec php -d pcov.enabled=1 vendor/bin/infection "$@"
