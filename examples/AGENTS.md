# Examples Agent Guide

This folder contains runnable sample apps for the SDK.

## Purpose

- `bootstrap-symfony-app` is the smallest useful integration sample.
- `merchant-symfony-app` is the richer reference app for realistic flows.

## Important Decisions

- Keep both apps plain Symfony. No Shopware code belongs here.
- The bootstrap app should stay small and easy to read.
- The merchant app can carry more realistic state, pricing, OAuth, and webhook examples.
- Integration tests in the bundle use both example apps as fixtures.

## What Not To Do

- Do not turn the bootstrap app into a production-like reference. That is the merchant app's job.
- Do not duplicate core or bundle logic here if the SDK can expose it through public contracts.

## Related Guides

- [bootstrap-symfony-app/AGENTS.md](bootstrap-symfony-app/AGENTS.md)
- [merchant-symfony-app/AGENTS.md](merchant-symfony-app/AGENTS.md)
