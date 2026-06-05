# Docker Agent Guide

This folder owns the repo-local container setup.

## Current Decisions

- The repo uses its own Docker setup. Do not reuse Shopware containers from other workspaces.
- The current base runtime is PHP `8.2`.
- The image is intentionally small and CLI-focused.
- The container mounts the full repo at `/workspace`.

## Files

- `../docker-compose.yml` defines the `php` service.
- `php/Dockerfile` installs Composer and the PHP extensions needed for QA and tests:
  - `pdo_sqlite`
  - `bcmath`
  - `intl`
  - `mbstring`
  - `dom`
  - `xmlwriter`

## What Belongs Here

- PHP runtime changes
- extra build dependencies required by repo tooling
- container-level changes needed by Composer, PHPUnit, PHPStan, CS Fixer, or PDepend

## What Does Not Belong Here

- Shopware-specific services
- unrelated app services
- production deployment assumptions
