#!/bin/sh

set -eu

BASE_REF="${MUTATION_BASE:-origin/main}"
CURRENT_DIR="$(pwd -P)"
git config --global --add safe.directory "${CURRENT_DIR}" >/dev/null 2>&1 || true

if [ "${CURRENT_DIR}" != "/workspace" ]; then
    git config --global --add safe.directory /workspace >/dev/null 2>&1 || true
fi

exec sh scripts/run-infection.sh \
  --configuration=infection.full.json.dist \
  --git-diff-filter=AM \
  --git-diff-base="${BASE_REF}" \
  --map-source-class-to-test \
  --only-covering-test-cases
