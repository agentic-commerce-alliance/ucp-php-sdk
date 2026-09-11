# Schema Layout

This directory contains two schema views for the same pinned UCP version.

## `pinned/`

`pinned/<version>` is the committed upstream schema snapshot with the original folder structure preserved. `pinned/2026-04-08` is kept alongside the active `pinned/2026-08-25` as a diffing reference for one release; only the active version has a `generated/` set.

Use it when you need to inspect the source material as published by the protocol version itself.

Example:

- `pinned/2026-08-25/schemas/ucp.json`
- `pinned/2026-08-25/schemas/profile.json`

## `generated/`

`generated/2026-08-25` contains the flattened request and response validator files that the SDK runtime loads directly.

Those files are intentionally arranged by operation name because `GeneratedSchemaValidator` resolves schema names such as:

- `catalog.search.request`
- `catalog.search.response`
- `checkout.create.request`
- `checkout.create.response`

## Why The Folder Structures Differ

They serve different jobs:

- `pinned/` keeps the upstream snapshot recognizable and reviewable.
- `generated/` keeps runtime validation lookup simple and fast.

The SDK validates against `generated/` at runtime and keeps `pinned/` as the source snapshot that explains where those runtime files came from.

## Sync Workflow

Schema sync starts from a checked-out upstream UCP release tag. The upstream
source files live under `source/`; this repo commits both the copied source
snapshot and the flattened runtime schemas.

Example:

```bash
git clone --depth 1 --branch v2026-08-25 https://github.com/Universal-Commerce-Protocol/ucp.git var/ucp-v2026-08-25
docker compose run --rm php bash scripts/sync-ucp-schemas.sh 2026-08-25 var/ucp-v2026-08-25
```

The version argument is required. It used to default, which meant omitting it regenerated
a version nobody had asked for.

## Verifying the generated set

`pinned/<version>` is the upstream `source/` tree, so `generated/<version>` is reproducible
from it with no network access:

```bash
docker compose run --rm php composer sync:verify
```

This runs as part of `composer qa` and covers every pinned version it finds. It fails when a
generated file was edited by hand, when an operation's output is missing or orphaned, and when
flattening cuts more recursive `$ref`s than `tools/sync-cycle-placeholder-budget.json` records
-- each of those cuts is a subtree that stops being validated, so the count is a gate rather
than a note.

The sync command:

1. copies upstream `source/discovery`, `source/schemas`, `source/services`, and
   `source/handlers` into `pinned/<version>`;
2. derives operation-specific request schemas from upstream named `$defs` and
   `ucp_request` annotations;
3. derives response schemas from the corresponding upstream response objects;
4. flattens relative `$ref` links so `GeneratedSchemaValidator` can validate
   each operation file without loading external schema files.
