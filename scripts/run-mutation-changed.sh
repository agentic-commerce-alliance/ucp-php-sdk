#!/bin/sh

set -eu

BASE_REF="${MUTATION_BASE:-origin/main}"

exec php -d pcov.enabled=1 vendor/bin/infection \
  --configuration=infection.full.json.dist \
  --threads=max \
  --git-diff-filter=AM \
  --git-diff-base="${BASE_REF}" \
  --map-source-class-to-test \
  --only-covering-test-cases
