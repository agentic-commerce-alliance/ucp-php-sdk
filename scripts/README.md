# Scripts

This folder holds small shell helpers.

Current scripts:

- `sync-ucp-schemas.sh` explains the expected flow for updating pinned and generated schema artifacts.
- `require-check.sh` reports dependencies a published package uses but does not declare, by
  installing each package on its own. The mirror of `composer unused-deps`, which only sees the
  opposite direction (#117).
- `comment-bc-since-release.sh` posts, and on re-runs edits, the single pull-request comment
  listing what has broken since the last release. A notice: pre-1.0 breaks are permitted, so
  they belong in the changelog rather than in a failed check.
- `bc-check.sh <baseline-ref> [<current-ref>]` runs the backward-compatibility check per
  published package. It exists because the checker analyses the *root* package's sources and
  this root has no `autoload`, so calling the tool directly reports nothing on any change
  (#115). Compares refs, not the working tree.
- `run-infection.sh` is the single funnel every mutation entry point execs into (`composer mutation`,
  `mutation:changed`, `mutation:gate`, `mutation:full`, `mutation:security`). It exists to supply the
  two ini settings this container has no `php.ini` to carry — `pcov.enabled=1` for coverage and
  `memory_limit=512M` for Infection's teardown — to resolve `MUTATION_THREADS` without clobbering a
  `--threads` the caller passed, and to echo both so a log always records what actually took effect.
- `run-mutation-changed.sh` is `run-infection.sh` plus Infection's git-diff filtering against
  `MUTATION_BASE` (default `origin/main`), narrowing a run to changed files and their covering tests.
- `run-composer-in-ci.sh` marks `/workspace` a safe git directory, then execs `composer`. CI calls it
  instead of `composer` directly because the repo is bind-mounted and owned by a different user than
  the container's root, which makes git refuse to operate on it.

For technical notes about why the scripts are intentionally small, see [AGENTS.md](AGENTS.md).
