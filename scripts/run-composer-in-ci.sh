#!/bin/sh

set -eu

git config --global --add safe.directory /workspace >/dev/null 2>&1 || true

exec composer "$@"
