# Packages Agent Guide

This folder contains the publishable SDK packages.

## Package Split

- `core` must stay framework-free and reusable outside Symfony.
- `symfony-bundle` may depend on Symfony and Doctrine DBAL, but it should still stay shop-agnostic.

## Design Boundary

- Put protocol models, stable contracts, events, exceptions, enums, repository interfaces, and shared services in `core`.
- Put HTTP transport wiring, Symfony DI config, controllers, listeners, and default DBAL repositories in `symfony-bundle`.
- Do not move bundle-only concerns back into `core`.
- Do not add Shopware-specific adapters here. Those belong in a separate
  platform plugin package such as `SwagAgenticCommerce`.

## Related Guides

- [core/AGENTS.md](core/AGENTS.md)
- [symfony-bundle/AGENTS.md](symfony-bundle/AGENTS.md)
