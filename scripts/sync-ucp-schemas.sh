#!/usr/bin/env bash

set -euo pipefail

TARGET_VERSION="${1:-2026-04-08}"

echo "This repository commits generated schema artifacts."
echo "Syncing official UCP schemas for version ${TARGET_VERSION} should be done inside the Docker PHP container."
echo "Expected workflow:"
echo "  1. Fetch pinned files from Universal-Commerce-Protocol/ucp"
echo "  2. Store source snapshots under packages/core/resources/schema/pinned/${TARGET_VERSION}"
echo "  3. Regenerate operation-specific artifacts under packages/core/resources/schema/generated/${TARGET_VERSION}"

