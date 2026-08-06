# Scripts

This folder holds small shell helpers.

Current scripts:

- `sync-ucp-schemas.sh` explains the expected flow for updating pinned and generated schema artifacts.
- `require-check.sh` reports dependencies a published package uses but does not declare, by
  installing each package on its own. The mirror of `composer unused-deps`, which only sees the
  opposite direction (#117).

For technical notes about why the script is intentionally small, see [AGENTS.md](AGENTS.md).
