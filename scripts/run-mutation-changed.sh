#!/bin/sh

set -eu

BASE_REF="${MUTATION_BASE:-origin/main}"
REPO_ROOT="$(git rev-parse --show-toplevel)"
git config --global --add safe.directory "${REPO_ROOT}" >/dev/null 2>&1 || true

exec sh scripts/run-infection.sh \
  --configuration=infection.full.json.dist \
  --threads=max \
  --git-diff-filter=AM \
  --git-diff-base="${BASE_REF}" \
  --map-source-class-to-test \
  --only-covering-test-cases
