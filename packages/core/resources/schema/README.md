# Schema Layout

This directory contains two schema views for the same pinned UCP version.

## `pinned/`

`pinned/2026-04-08` is the committed upstream schema snapshot with the original folder structure preserved.

Use it when you need to inspect the source material as published by the protocol version itself.

Example:

- `pinned/2026-04-08/schemas/ucp.json`
- `pinned/2026-04-08/schemas/discovery/business_profile.json`

## `generated/`

`generated/2026-04-08` contains the flattened request and response validator files that the SDK runtime loads directly.

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
