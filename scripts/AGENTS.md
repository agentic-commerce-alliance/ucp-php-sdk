# Scripts Agent Guide

This folder holds small helper shell scripts.

## Current Scripts

- `sync-ucp-schemas.sh` is an instructional helper, not a full sync pipeline
- `bc-check.sh` and `comment-bc-since-release.sh` run and report the backward-compatibility check
- `require-check.sh` finds dependencies a published package uses but does not declare
- `run-infection.sh` is the single funnel every mutation entry point execs into
- `run-mutation-changed.sh` layers git-diff filtering onto `run-infection.sh`
- `run-composer-in-ci.sh` marks `/workspace` git-safe, then execs `composer`

See [README.md](README.md) for what each one does and why it exists.

## Important Decisions

- The repo commits pinned schema snapshots and generated runtime artifacts
- Runtime code must not depend on upstream schema tools
- Schema sync work should run inside the repo Docker container
- The container ships no `php.ini`, so per-tool ini settings belong on the `php -d` invocation in the
  script that runs that tool, not in a `conf.d/*.ini` in the image. A repo-wide ceiling would raise
  it for phpunit, cs-fixer and pdepend too, which all pass at the `128M` default today — masking the
  next memory regression anywhere in the toolchain instead of localising the exception to the one
  tool with a known spike.
- `run-infection.sh` therefore sets `memory_limit=512M`: Infection's teardown hands the whole mutant
  `tmpDir` to Symfony's `Filesystem::remove()`, a 96MB spike for 4000 mutant directories that lands
  *after* the sweep has produced its verdict. At the `128M` default a passing sweep still exited 255.
  Keep it finite — `-1` trades a PHP fatal naming a file and line for an opaque container OOM kill.
- Variables these scripts read (`MUTATION_THREADS`, `MUTATION_BASE`) must also be declared in
  `docker-compose.yml`'s `environment:` map. Compose does not forward host variables it has not been
  told about, so a new one works locally in the container and silently does nothing from a workflow
  `env:` block until it is declared there.
- Prefer echoing effective settings over trusting them. The `MUTATION_THREADS=4` pin read as true in
  four documents while CI ran `7` for months, because nothing ever printed the real value.

## Editing Rules

- keep shell scripts small and explicit
- prefer documenting the expected workflow over hiding important steps in opaque shell logic
