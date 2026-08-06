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

For technical notes about why the script is intentionally small, see [AGENTS.md](AGENTS.md).
