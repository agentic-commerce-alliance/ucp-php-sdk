#!/bin/sh
# Detects dependencies a published package USES but does not DECLARE.
#
# `composer unused-deps` checks the opposite direction — declared but unused — and is
# structurally blind to this one, which is how the bundle shipped six commands extending
# `Symfony\Component\Console\Command\Command` without requiring symfony/console (#117).
# They resolved transitively through symfony/framework-bundle, which does not hard-require
# console, so a standalone install fataled on the first command that autoloaded.
#
# ComposerRequireChecker analyses one package's sources against that package's own
# `require`, and needs that package's dependencies installed next to it. The monorepo
# installs everything at the root instead, and the root declares every dev tool in the
# repo — checking there would find nothing missing, ever. So each package is copied out and
# installed on its own, which is also the shape a consumer gets.
#
# The bundle is checked against the working tree's core rather than the released one, via a
# path repository: core and the bundle change together between releases, and a new core
# symbol is a version-floor question rather than an undeclared dependency.
set -eu

repo="$(git rev-parse --show-toplevel)"
checker="${repo}/vendor/bin/composer-require-checker"
config="${repo}/composer-require-checker.json"
status=0

for package in core symfony-bundle; do
    printf '\n=== %s ===\n' "${package}"

    work="$(mktemp -d)"
    cp -R "${repo}/packages/${package}/." "${work}"

    if [ "${package}" != "core" ]; then
        composer --working-dir="${work}" --quiet config repositories.core \
            "{\"type\": \"path\", \"url\": \"${repo}/packages/core\", \"options\": {\"symlink\": false, \"versions\": {\"ucp-php-sdk/core\": \"0.0.4\"}}}"
    fi

    composer --working-dir="${work}" --quiet --no-interaction install --no-scripts

    if ! "${checker}" check --config-file="${config}" "${work}/composer.json"; then
        status=1
    fi

    rm -rf "${work}"
done

exit "${status}"
