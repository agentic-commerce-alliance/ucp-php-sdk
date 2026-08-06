#!/bin/sh
# Posts (or updates) the one pull-request comment saying what has broken since the last
# release, from a scripts/bc-check.sh log.
#
# This is a NOTICE, never a gate. The blocking check compares against the branch the pull
# request targets, so it answers "does this pull request break compatibility?". This one
# compares against the newest release tag and answers a release question instead: pre-1.0
# breaks are permitted, and what they need is to be declared in CHANGELOG.md and reflected in
# the version bump. Failing a merge over one — which is what a single tag-based gate did to
# every pull request after #114 landed — answers the wrong question.
#
# The comment is keyed by a hidden marker and edited in place, so ten re-runs leave one
# comment rather than ten. `gh pr comment --edit-last` would have been shorter and wrong: it
# edits whatever the actor commented last, which is not necessarily this.
#
# Usage: GH_TOKEN=… PR=<number> TAG=<tag> scripts/comment-bc-since-release.sh <log-file>
set -eu

log="${1:?usage: scripts/comment-bc-since-release.sh <log-file>}"
: "${PR:?PR is required}"
: "${TAG:?TAG is required}"
repo="${GITHUB_REPOSITORY:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}"
marker='<!-- bc-since-release -->'

findings="$(grep -E '^\[BC\]' "${log}" || true)"

# The backticks and $-free braces below are markdown for the comment body, so the format
# strings stay single-quoted and the shell must not touch them.
# shellcheck disable=SC2016
if [ -z "${findings}" ]; then
    body="$(printf '%s\n### Backward compatibility since `%s`\n\nNo backwards-incompatible changes. Nothing to declare for the next release.\n' "${marker}" "${TAG}")"
else
    count="$(printf '%s\n' "${findings}" | wc -l | tr -d ' ')"
    body="$(printf '%s\n### Backward compatibility since `%s`\n\n**%s change(s)** on the published packages, relative to the last release:\n\n```\n%s\n```\n\nThis is a notice, not a failure — the blocking check compares against the target branch, so\nnothing here was necessarily introduced by this pull request. `0.0.x` allows breaks; what they\nneed is an entry in `CHANGELOG.md` and a version bump that reflects them.\n' \
        "${marker}" "${TAG}" "${count}" "${findings}")"
fi

# Existing comment carrying the marker, if any. `--paginate` because a long-lived pull request
# can easily push it past the first page.
existing="$(gh api --paginate "repos/${repo}/issues/${PR}/comments" \
    --jq "map(select(.body | contains(\"${marker}\"))) | first | .id // empty")"

if [ -n "${existing}" ]; then
    gh api --method PATCH "repos/${repo}/issues/comments/${existing}" -f body="${body}" >/dev/null
    echo "Updated comment ${existing}."
else
    gh api --method POST "repos/${repo}/issues/${PR}/comments" -f body="${body}" >/dev/null
    echo "Posted a new comment."
fi
