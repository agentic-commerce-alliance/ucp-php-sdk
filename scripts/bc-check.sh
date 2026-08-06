#!/bin/sh
# Backward-compatibility check for the published packages.
#
# Roave's checker analyses the *root* package's sources, and this repo's root
# (`ucp-php-sdk/monorepo`) declares no `autoload` at all — every class lives in
# packages/core and packages/symfony-bundle, which the root consumes as path-repo
# dependencies. So running the tool at the root compares nothing and passes
# unconditionally: a required constructor argument added to a published model came
# back as "No backwards-incompatible changes detected" (issue #115).
#
# Giving the root an `autoload` does make the tool see the sources, and was the first
# fix tried here. It breaks `composer unused-deps`, which then reads those same files
# as root code and reports the package requirements as unused. Fighting one gate to
# satisfy another is not a fix.
#
# So each package is compared as what it is published as: its own repository. The
# baseline tree and the current tree are committed into a throwaway repo, one after the
# other, and the checker runs there. That also works for baseline tags that predate this
# script, which the autoload approach could not — that one needs the mapping present on
# both sides of the comparison.
#
# Compares REFS, not the working tree: commit before trusting a clean result.
#
# Usage: scripts/bc-check.sh <baseline-git-ref> [<current-git-ref>]
set -eu

baseline="${1:?usage: scripts/bc-check.sh <baseline-ref> [<current-ref>]}"
current="${2:-HEAD}"
repo="$(git rev-parse --show-toplevel)"
checker="${repo}/vendor/bin/roave-backward-compatibility-check"
status=0

for package in core symfony-bundle; do
    printf '\n=== %s: %s -> %s ===\n' "${package}" "${baseline}" "${current}"

    if ! git -C "${repo}" cat-file -e "${baseline}:packages/${package}/composer.json" 2>/dev/null; then
        echo "packages/${package} does not exist at ${baseline}; nothing to compare."
        continue
    fi

    work="$(mktemp -d)"
    git -C "${repo}" init -q "${work}"

    # Two commits in one throwaway repo, because --from/--to are refs rather than
    # directories. The tree is emptied in between so a deleted file shows up as a
    # deletion rather than as a leftover.
    #
    # Each side also gets the monorepo's dev dependencies merged into its composer.json,
    # because a class the checker cannot locate is reported as `[BC] SKIPPED` and fails
    # the run on that instead of on real findings — 49 of those without this, every one
    # the `Symfony\Component\Console\Command\Command` parent of the bundle's commands,
    # which the bundle's own composer.json does not declare. (Worth fixing separately:
    # those commands work today only because framework-bundle happens to pull console
    # in.) Injecting them here rather than relying on package metadata also keeps the
    # check working against baselines that predate such a fix.
    for ref in "${baseline}" "${current}"; do
        find "${work}" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
        git -C "${repo}" archive "${ref}" "packages/${package}" | tar -x -C "${work}" --strip-components=2

        # $argv below is PHP's and must stay unexpanded, hence the single quotes.
        # shellcheck disable=SC2016
        php -r '
            $file = $argv[1] . "/composer.json";
            if (! is_file($file)) { return; }
            $package = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $root = json_decode(file_get_contents($argv[2] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
            $package["require-dev"] = array_merge($root["require-dev"] ?? [], $package["require-dev"] ?? []);
            unset($package["require-dev"]["ucp-php-sdk/core"], $package["require-dev"]["ucp-php-sdk/symfony-bundle"]);
            file_put_contents($file, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        ' "${work}" "${repo}"

        git -C "${work}" add -A
        git -C "${work}" -c user.email=bc@example.invalid -c user.name='BC check' \
            commit -q --allow-empty -m "${package} @ ${ref}"
    done

    if ! (cd "${work}" && "${checker}" --from=HEAD~1 --to=HEAD --install-development-dependencies); then
        status=1
    fi

    rm -rf "${work}"
done

exit "${status}"
