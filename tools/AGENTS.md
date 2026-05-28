# Tools Agent Guide

This folder contains small repo maintenance tools.

## Public API Snapshot

- `build-public-api-snapshot.php` scans the stable namespaces under `packages/core/src`
- it writes the current snapshot to `public-api-snapshot.txt`
- `check-public-api-snapshot.php` rebuilds the snapshot and compares it to `public-api-snapshot.expected.txt`

## Dead Code And Coverage

- `check-internal-class-references.php` only scans internal and runtime concrete classes
- it must never be broadened to public SDK contracts without an explicit decision
- `internal-class-allowlist.php` is the reviewed exception list and every entry needs a reason plus an owner
- `report-internal-coverage.php` reads Clover XML and reports the internal target bands used for the later phase-2 gate

## Why This Exists

- the project wants a curated stable public surface
- snapshot drift is treated as an explicit review point
- this is lighter weight than publishing full generated API docs during local development

## Editing Rules

- keep these scripts simple and local
- prefer reflection and file scanning over adding big tool dependencies
- if the stable namespace list changes, update both the tool and the extension contract docs
- if the internal scan scope changes, update `docs/qa-dead-code.md` and the relevant package agent guides
