# Release Process

This repo should use GitHub's built-in release features for version-specific release notes.

Do not keep a separate root `RELEASE_INFO.md` file for per-version notes. That creates two sources of truth.

## Source Of Truth

- Git tag:
  - the version identifier
- GitHub Release:
  - the canonical release notes for that tag
- Release Drafter:
  - the helper that keeps the next draft release current between tags
- `CHANGELOG.md`:
  - a short repo-local summary of released versions
- docs in `docs/`:
  - evergreen release policy and process only

## Why

- release notes are inherently version-specific
- GitHub Releases already model tags, titles, notes, and attached artifacts
- consumers expect to read release notes in the GitHub UI
- a release drafter workflow reduces manual note assembly before tagging
- the repo docs should explain process and support boundaries, not duplicate every release note

## Versioning Shape

Current package line:

- `ucp-php-sdk/core`
- `ucp-php-sdk/symfony-bundle`

Current maturity:

- pre-`1.0`
- alpha or beta tags are acceptable

Recommended tag examples:

- `0.0.1-alpha1`
- `0.0.1-alpha2`
- `0.0.1-beta1`
- `0.0.1`

## Packagist Distribution

The two publishable packages live in this monorepo under `packages/`, but
Packagist serves one package per repository (it reads each repository's root
`composer.json`). They are mirrored into standalone read-only repositories by
the `split-packages` workflow:

| Monorepo path | Package | Mirror repository |
| --- | --- | --- |
| `packages/core` | `ucp-php-sdk/core` | `agentic-commerce-alliance/ucp-php-sdk-core` |
| `packages/symfony-bundle` | `ucp-php-sdk/symfony-bundle` | `agentic-commerce-alliance/ucp-php-sdk-symfony-bundle` |

One-time setup:

1. Create the two mirror repositories in the `agentic-commerce-alliance` org.
2. Add a `SPLIT_TOKEN` repository/org secret with write access to both mirrors.
3. Register both mirrors on Packagist and enable the GitHub service hook so new
   tags publish automatically.

Ongoing:

- A push to `main` keeps the mirrors in sync.
- A pushed release tag (below) is propagated to both mirrors; Packagist
  publishes the matching version.

## Before Tagging

1. Run the full QA gate:

```bash
docker compose run --rm php composer qa
```

2. Confirm package metadata is correct:

```bash
docker compose run --rm php composer validate --strict
docker compose run --rm php composer validate --strict -d packages/core
docker compose run --rm php composer validate --strict -d packages/symfony-bundle
```

3. Make sure the public API snapshot is current:

```bash
docker compose run --rm php composer public-api:check
```

   `composer qa` already runs `sync:verify`, which regenerates every pinned schema set and
   fails if the committed output differs. If that is red, the generated schemas no longer
   follow from the pinned upstream copy and the release would ship validators nobody can
   reproduce.

4. Check where the release stands against the upstream conformance suite:

```bash
./scripts/run-conformance.sh
```

   Not a gate — see [conformance.md](conformance.md) for which modules are enforced — but the
   release note should not claim conformance the suite does not show.

5. Update `CHANGELOG.md` with a short summary entry for the version being tagged.

6. Confirm the release posture is accurate in the main docs:
   - install commands in [README.md](../README.md)
   - current scope and boundaries in [docs/extension-contract.md](extension-contract.md), [docs/security-model.md](security-model.md), and [docs/platform-adapters.md](platform-adapters.md)
   - production readiness items in [docs/production-operator-checklist.md](production-operator-checklist.md)
   - contributor workflow in [CONTRIBUTING.md](../CONTRIBUTING.md)

## Tag And Release Flow

1. Create the tag locally:

```bash
git tag 0.0.1
```

2. Push the tag:

```bash
git push origin 0.0.1
```

3. Create a GitHub Release from that tag.

4. Start from the existing Release Drafter draft or GitHub's generated notes, then edit them into a short curated note set.

## What A GitHub Release Note Should Contain

Each GitHub Release should include:

- release maturity:
  - alpha, beta, or stable
- package install targets:
  - `ucp-php-sdk/core`
  - `ucp-php-sdk/symfony-bundle`
- protocol target:
  - currently UCP `2026-04-08`
- main included scope:
  - discovery
  - catalog
  - cart
  - checkout
  - tokenization
  - identity linking
  - order read
  - outbound order webhooks
  - MCP
  - A2A
  - embedded transport hooks
- explicit out-of-scope list:
  - Shopware-specific MCP tools and Store API wiring
  - Shopware admin UI and DAL definitions
  - full AP2 credential stack
- operational defaults or caveats relevant to that release
- breaking changes or removed pre-release APIs
- upgrade notes if the previous tag needs manual changes

## Alpha And Pre-1.0 Notes

For pre-`1.0` releases, the GitHub Release should call out the important caveats directly instead of storing them in a permanent repo file.

Examples of caveats that belong in release notes when relevant:

- deterministic JSON versus certified full RFC 8785 JCS
- schema-validator coverage boundaries
- intentionally removed pre-release compatibility shims
- known limitations in current transport or storage support

## What Stays In The Repo

Keep these release-related concerns in the repo:

- `CHANGELOG.md` for compact historical summaries
- this release-process document
- install commands in [README.md](../README.md)
- evergreen scope, security, adapter, and extension docs

Do not create new files like `RELEASE_INFO.md`, `ALPHA_NOTES.md`, or `CURRENT_RELEASE.md` unless there is a strong reason the information cannot live in GitHub Releases or the existing docs.

## Suggested GitHub Release Template

```md
## Summary

- short description of the release

## Packages

- `ucp-php-sdk/core`
- `ucp-php-sdk/symfony-bundle`

## Included Scope

- discovery
- catalog
- cart
- checkout
- tokenization
- identity linking
- order read
- outbound order webhooks

## Operational Notes

- any new defaults, commands, or constraints

## Caveats

- pre-1.0 limitations or explicit non-goals

## Upgrade Notes

- anything users must change from the previous tag
```

## Agent Note

If an AI agent is preparing a release:

- update `CHANGELOG.md`
- verify `composer qa`
- do not invent a new release-info file
- put version-specific notes into the GitHub Release draft
