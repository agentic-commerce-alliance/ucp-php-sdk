# Repo Layout

This document explains where code and docs belong in this repo.

Use it when you are unsure where to place a new class, config file, test, or note.

## Top-Level Layout

```mermaid
flowchart TD
    A["Repo root"] --> B["packages/core"]
    A --> C["packages/symfony-bundle"]
    A --> D["examples"]
    A --> E["docs"]
    A --> F["tools"]
    A --> G["docker"]
    A --> H["scripts"]
```

- `packages/core`: framework-free SDK code
- `packages/symfony-bundle`: Symfony HTTP wiring and default storage adapters
- `examples`: sample apps
- `docs`: cross-cutting docs and architecture notes
- `tools`: local helper scripts and QA helpers
- `docker`: repo-local container setup
- `scripts`: helper scripts for repo maintenance

## Placement Rules

| If you are adding... | Put it here | Why |
| --- | --- | --- |
| Public DTOs | `packages/core/src/Model` | Shared protocol model |
| Public capability or adapter contracts | `packages/core/src/Contract` or `packages/core/src/Adapter` | Stable extension surface |
| Public service interfaces | `packages/core/src/Service` | Replaceable integration hooks |
| Public repository interfaces | `packages/core/src/Repository` | Replaceable storage contracts |
| Default runtime implementations | `packages/core/src/Internal` | Internal shared behavior |
| Symfony controllers, listeners, commands | `packages/symfony-bundle/src` | Bundle-owned HTTP and framework wiring |
| Default DBAL storage adapters | `packages/symfony-bundle/src/Bridge/DoctrineDbal` | Default Symfony storage only |
| Example merchant logic | `examples/merchant-symfony-app/src` | Demo code, not shared SDK |
| Tiny integration sample code | `examples/bootstrap-symfony-app/src` | Minimal reference |
| Cross-cutting docs | `docs` | Repo-wide explanation |
| QA and support scripts | `tools` | Local tooling |

## Decision Flow

```mermaid
flowchart TD
    A["New code"] --> B{"Is it public SDK API?"}
    B -- "Yes" --> C{"Is it protocol model or contract?"}
    C -- "DTO or enum" --> D["packages/core/src/Model or Enum"]
    C -- "Capability, adapter, or service contract" --> E["packages/core/src/Contract, Adapter, Service, or Repository"]
    B -- "No" --> F{"Is it shared runtime behavior?"}
    F -- "Yes" --> G["packages/core/src/Internal"]
    F -- "No" --> H{"Is it Symfony-only wiring?"}
    H -- "Yes" --> I["packages/symfony-bundle/src"]
    H -- "No" --> J{"Is it example-only?"}
    J -- "Yes" --> K["examples/.../src"]
    J -- "No" --> L["docs, tools, docker, or future plugin repo"]
```

## Core Package Structure

```mermaid
flowchart TD
    A["packages/core/src"] --> B["Adapter"]
    A --> C["Contract"]
    A --> D["Enum"]
    A --> E["Event"]
    A --> F["Exception"]
    A --> G["Internal"]
    A --> H["Model"]
    A --> I["Repository"]
    A --> J["Service"]
```

Use these rules:

- `Adapter`: platform adapter contracts and adapter-backed capabilities
- `Contract`: public business capability and extension contracts
- `Internal`: default implementations and runtime helpers
- `Model`: immutable public DTOs
- `Repository`: public storage contracts
- `Service`: public service interfaces

## Symfony Bundle Structure

```mermaid
flowchart TD
    A["packages/symfony-bundle/src"] --> B["Bridge"]
    A --> C["Command"]
    A --> D["Controller"]
    A --> E["DependencyInjection"]
    A --> F["EventListener"]
```

Use these rules:

- `Bridge`: framework or storage adapters, not platform-domain mapping
- `Command`: operator commands such as signing-key helpers
- `Controller`: REST endpoints
- `DependencyInjection`: bundle registration and service wiring
- `EventListener`: request or response listeners

## What Must Stay Out Of This Repo Area

```mermaid
flowchart LR
    A["Shared SDK repo"] -. not here .-> B["Shopware entity classes"]
    A -. not here .-> C["Shopware DAL definitions"]
    A -. not here .-> D["Shopware admin module"]
    A -. not here .-> E["MCP transport runtime"]
```

- The future Shopware plugin belongs on top of this SDK, not inside `packages/core`.
- MCP stays out of the shared SDK.
- Doctrine DBAL bridge code is a default Symfony storage adapter, not the model every adopter must use.

## Test Placement

| Test type | Put it here |
| --- | --- |
| Core unit test | `packages/core/tests/Unit` |
| Bundle unit or integration test | `packages/symfony-bundle/tests` |
| Example app behavior test | in the main repo PHPUnit suites, using the example app source |

## Docs Placement

| Doc type | Put it here |
| --- | --- |
| Human repo overview | root `README.md` |
| Folder-specific human guide | local `README.md` in that folder |
| Technical agent guidance | local `AGENTS.md` in that folder |
| Cross-cutting architecture note | `docs/*.md` |

## Quick Examples

- New shared checkout DTO: `packages/core/src/Model/Checkout`
- New default RFC 9421 helper: `packages/core/src/Internal/Security`
- New bundle route: `packages/symfony-bundle/src/Controller`
- New default DBAL repository: `packages/symfony-bundle/src/Bridge/DoctrineDbal`
- New Shopware-specific cart mapping: not in this repo area, put it in the future Shopware plugin
