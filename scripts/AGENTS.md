# Scripts Agent Guide

This folder holds small helper shell scripts.

## Current Script

- `sync-ucp-schemas.sh` is an instructional helper, not a full sync pipeline

## Important Decisions

- The repo commits pinned schema snapshots and generated runtime artifacts
- Runtime code must not depend on upstream schema tools
- Schema sync work should run inside the repo Docker container

## Editing Rules

- keep shell scripts small and explicit
- prefer documenting the expected workflow over hiding important steps in opaque shell logic
