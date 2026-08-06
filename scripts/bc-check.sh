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
# The baseline decides which question is being answered, and CI asks both:
#
#   origin/<target branch>   does THIS change break compatibility?     -> blocking
#   <newest release tag>     has anything broken since the release?     -> notice only
#
# The second is a release question — pre-1.0 breaks are allowed, and what they need is a
# CHANGELOG entry and a version bump. Gating merges on it means one break on the base branch
# fails every later pull request, which is what happened to #118 the moment this check began
# working. See scripts/comment-bc-since-release.sh for how that answer is reported instead.
#
# Usage: scripts/bc-check.sh <baseline-git-ref> [<current-git-ref>]
set -eu

baseline="${1:?usage: scripts/bc-check.sh <baseline-ref> [<current-ref>]}"
current="${2:-HEAD}"
repo="$(git rev-parse --show-toplevel)"
checker="${repo}/vendor/bin/roave-backward-compatibility-check"
status=0

# The version the root composer.json forces on the path repositories, read rather than
# repeated: it moves with every release, and a copy here would be one more place for this
# repository to disagree with itself. A hardcoded literal is exactly what broke when 0.0.5
# raised the bundle's floor on core.
# shellcheck disable=SC2016
core_version="$(php -r '
    $root = json_decode(file_get_contents($argv[1] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
    foreach ($root["repositories"] ?? [] as $repository) {
        if (isset($repository["options"]["versions"]["ucp-php-sdk/core"])) {
            echo $repository["options"]["versions"]["ucp-php-sdk/core"];
            return;
        }
    }
    fwrite(STDERR, "the root composer.json forces no ucp-php-sdk/core version\n");
    exit(1);
' "${repo}")"

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

        # The sibling comes from the working tree, not Packagist. Without this the bundle is
        # compared against whatever core is *published*, so a release raising its floor cannot
        # be checked until the tag exists — and that install failure was silent, which is how
        # this script reported "no backwards-incompatible changes" for a comparison it never
        # made.
        if [ "${package}" != "core" ]; then
            composer --working-dir="${work}" --quiet config repositories.core \
                "{\"type\": \"path\", \"url\": \"${repo}/packages/core\", \"options\": {\"symlink\": false, \"versions\": {\"ucp-php-sdk/core\": \"${core_version}\"}}}"
        fi

        git -C "${work}" add -A
        git -C "${work}" -c user.email=bc@example.invalid -c user.name='BC check' \
            commit -q --allow-empty -m "${package} @ ${ref}"
    done

    if ! output="$(cd "${work}" && "${checker}" --from=HEAD~1 --to=HEAD --install-development-dependencies 2>&1)"; then
        status=1
    fi
    printf '%s\n' "${output}"

    # A verdict, or nothing happened. The checker prints one of these two lines whenever it
    # actually compared something; anything else — a failed install of either side, most
    # likely — must not read as compatibility confirmed.
    if ! printf '%s' "${output}" | grep -qE '([0-9]+|No) backwards-incompatible changes detected'; then
        echo "bc-check: ${package} produced no verdict; treating that as a failure rather than as clean." >&2
        status=1
    fi

    rm -rf "${work}"
done

exit "${status}"
