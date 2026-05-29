#!/bin/sh

set -eu

CURRENT_DIR="$(pwd -P)"
git config --global --add safe.directory "${CURRENT_DIR}" >/dev/null 2>&1 || true

if [ "${CURRENT_DIR}" != "/workspace" ]; then
    git config --global --add safe.directory /workspace >/dev/null 2>&1 || true
fi

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || printf '%s' "${CURRENT_DIR}")"
git config --global --add safe.directory "${REPO_ROOT}" >/dev/null 2>&1 || true

exec php -d pcov.enabled=1 vendor/bin/infection "$@"
