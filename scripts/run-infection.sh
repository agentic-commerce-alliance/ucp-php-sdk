#!/bin/sh

set -eu

CURRENT_DIR="$(pwd -P)"
git config --global --add safe.directory "${CURRENT_DIR}" >/dev/null 2>&1 || true

if [ "${CURRENT_DIR}" != "/workspace" ]; then
    git config --global --add safe.directory /workspace >/dev/null 2>&1 || true
fi

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || printf '%s' "${CURRENT_DIR}")"
git config --global --add safe.directory "${REPO_ROOT}" >/dev/null 2>&1 || true

THREADS="${MUTATION_THREADS:-7}"
HAS_THREADS_ARG=0

for ARG in "$@"; do
    case "$ARG" in
        --threads|--threads=*|-j|-j*)
            HAS_THREADS_ARG=1
            break
            ;;
    esac
done

if [ "$HAS_THREADS_ARG" -eq 1 ]; then
    exec php -d pcov.enabled=1 vendor/bin/infection "$@"
fi

exec php -d pcov.enabled=1 vendor/bin/infection "$@" --threads="${THREADS}"
