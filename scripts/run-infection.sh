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

# Infection's teardown hands the whole mutant tmpDir to Symfony's Filesystem::remove(), which
# materialises every path into one array, copies it again via array_reverse() and recurses per
# directory level. Measured in this container, that alone peaks at 96MB for 4000 mutant directories
# -- a spike that lands *after* the sweep has already produced its verdict. The image ships no
# php.ini (`Loaded Configuration File => (none)`), so PHP's compiled-in 128M default applies, and
# the full sweep was already peaking at exactly that ("Memory: 0.13GB" == the ceiling). Teardown
# therefore turned a passing sweep into `exit 255` with a fatal in Filesystem.php, at random,
# depending on which side of the ceiling a given run landed.
#
# Finite rather than -1 on purpose: a real leak should still fail as a PHP fatal naming a file and
# line -- that message is the only reason this was diagnosable -- instead of an opaque container OOM
# kill. -1 would also flip Infection's own MemoryLimiterEnvironment::hasMemoryLimitSet() onto a
# different code path, which is currently inert only because this container has no php.ini to write.
MEMORY_LIMIT=512M

HAS_THREADS_ARG=0
THREADS_LABEL="${THREADS}"

for ARG in "$@"; do
    case "$ARG" in
        --threads|--threads=*|-j|-j*)
            HAS_THREADS_ARG=1
            THREADS_LABEL='from arguments'
            break
            ;;
    esac
done

# Echo the settings that actually took effect. The bug this guards against is not a crash but a
# silent lie: `MUTATION_THREADS=4` was pinned in both workflows and asserted in four documents while
# CI really ran 7, because the variable never reached the container. Cross-check this line against
# Infection's own "Threads: N" in the summary.
printf 'run-infection: memory_limit=%s threads=%s\n' "${MEMORY_LIMIT}" "${THREADS_LABEL}" >&2

if [ "$HAS_THREADS_ARG" -eq 1 ]; then
    exec php -d pcov.enabled=1 -d memory_limit="${MEMORY_LIMIT}" vendor/bin/infection "$@"
fi

exec php -d pcov.enabled=1 -d memory_limit="${MEMORY_LIMIT}" vendor/bin/infection "$@" --threads="${THREADS}"
