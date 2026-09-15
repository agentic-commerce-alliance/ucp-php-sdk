# Docs Agent Guide

This folder stores cross-cutting architecture notes.

## What Belongs Here

- stable extension rules
- adapter architecture
- protocol-to-platform mapping explanations
- storage boundary notes
- security model summaries
- blueprint docs for future platform plugins

## Editing Rules

- keep these docs close to the current public contracts
- link back to package `README.md` and `AGENTS.md` files instead of copying package internals
- update these docs when the public extension model changes
- document generic transport parity in the shared SDK while keeping Shopware-specific MCP tooling in plugin docs

## Main Documents

The index in [README.md](README.md) is the list of record, grouped by purpose. When a document
is added, renamed or retired, update that index in the same change; this file does not repeat it.
