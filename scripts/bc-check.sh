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
# Breaks listed in .bc-allowed-breaks.txt are subtracted before deciding, because Roave
# offers no per-symbol ignore and this repository breaks published API on purpose pre-1.0.
# Anything not listed there still fails. See that file for the rules.
#
# Usage: scripts/bc-check.sh <baseline-git-ref> [<current-git-ref>]
set -eu

baseline="${1:?usage: scripts/bc-check.sh <baseline-ref> [<current-ref>]}"
current="${2:-HEAD}"
repo="$(git rev-parse --show-toplevel)"
checker="${repo}/vendor/bin/roave-backward-compatibility-check"
allowlist='.bc-allowed-breaks.txt'
findings_file="$(mktemp)"
waived_file="$(mktemp)"
trap 'rm -f "${findings_file}" "${waived_file}"' EXIT
status=0

# Read the declared breaks from the ref under test, not from the working tree: this script
# compares refs, and an allowlist that only exists uncommitted would waive a break that the
# branch does not actually declare. In CI the ref under test is the pull request head, so the
# file the author wrote is the one that governs.
#
# Only whole-line comments are supported. Roave writes findings as Class#method(), so treating
# '#' as starting a trailing comment would truncate half of them.
if git -C "${repo}" cat-file -e "${current}:${allowlist}" 2>/dev/null; then
    git -C "${repo}" show "${current}:${allowlist}" \
        | grep -vE '^[[:space:]]*(#|$)' \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/[[:space:]]\{1,\}/ /g' \
        > "${waived_file}" || true
else
    : > "${waived_file}"
fi

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

    package_status=0
    if ! output="$(cd "${work}" && "${checker}" --from=HEAD~1 --to=HEAD --install-development-dependencies 2>&1)"; then
        package_status=1
    fi
    printf '%s\n' "${output}"

    # A verdict, or nothing happened. The checker prints one of these two lines whenever it
    # actually compared something; anything else — a failed install of either side, most
    # likely — must not read as compatibility confirmed. Never waivable: a comparison that
    # did not happen is not a break someone can have accepted in advance.
    if ! printf '%s' "${output}" | grep -qE '([0-9]+|No) backwards-incompatible changes detected'; then
        echo "bc-check: ${package} produced no verdict; treating that as a failure rather than as clean." >&2
        rm -rf "${work}"
        status=1
        continue
    fi

    if [ "${package_status}" -ne 0 ]; then
        # Roave has no per-symbol ignore and no baseline file, so the only lever it offers is
        # all-or-nothing. Pre-1.0 this repository *does* break things deliberately — what such a
        # break needs is a CHANGELOG entry and a version bump, not a blocked merge — and with no
        # way to say so, the blocking run had to be either disabled or worked around.
        #
        # Findings listed in .bc-allowed-breaks.txt are therefore subtracted before deciding.
        # Anything not listed still fails, so the gate keeps catching the breaks nobody meant.
        printf '%s\n' "${output}" \
            | grep -E '^[[:space:]]*\[BC\]' \
            | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/[[:space:]]\{1,\}/ /g' \
            > "${findings_file}" || true

        remaining="$(grep -Fxv -f "${waived_file}" "${findings_file}" || true)"
        if [ -n "${remaining}" ]; then
            echo "bc-check: ${package}: breaks not declared in ${allowlist} (at ${current}):" >&2
            printf '%s\n' "${remaining}" >&2
            status=1
        else
            echo "bc-check: ${package}: every reported break is declared in ${allowlist}; not failing." >&2
        fi

        # An entry that matches nothing is a notice, not a failure: CI runs this script twice
        # against different baselines (the target branch, and the newest release tag), so an
        # entry that is live for one answer is legitimately stale for the other.
        unmatched="$(grep -Fxv -f "${findings_file}" "${waived_file}" || true)"
        if [ -n "${unmatched}" ]; then
            echo "bc-check: ${package}: breaks declared in ${allowlist} that did not occur (stale, or for the other baseline):" >&2
            printf '%s\n' "${unmatched}" >&2
        fi
    fi

    rm -rf "${work}"
done

exit "${status}"
