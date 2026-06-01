# Concepts And Flows

This document explains the main SDK concepts and the request flow from HTTP to platform code and back.

Use this together with [mapping-flow.md](./mapping-flow.md) and [repo-layout.md](./repo-layout.md).

## Main Layers

```mermaid
flowchart LR
    A["HTTP request"] --> B["Symfony bundle"]
    B --> C["Core SDK"]
    C --> D["Platform adapters"]
    D --> E["Commerce platform"]
```

- The Symfony bundle owns HTTP wiring and JSON protocol mapping.
- The core SDK owns protocol DTOs, validation, signing, idempotency, negotiation, and shared orchestration.
- Platform adapters translate between the shared SDK and a real commerce platform such as Shopware.

## Inbound Request Flow

```mermaid
flowchart TD
    A["Platform sends UCP request"] --> B["Symfony route and controller"]
    B --> C["HttpPayloadMapper"]
    C --> D["HttpRequestContextFactory"]
    D --> E["Runtime configuration"]
    D --> F["Platform profile fetch"]
    D --> G["Request signature verification"]
    D --> H["Capability negotiation"]
    H --> I["Capability service"]
    I --> J["Adapter-backed capability or custom capability"]
    J --> K["Platform adapter or direct capability logic"]
    K --> L["Public SDK DTO"]
    L --> M["ProtocolValidator response check"]
    M --> N["Symfony JSON response"]
```

Notes:

- `HttpPayloadMapper` is protocol mapping only. It must not become a platform mapper.
- `HttpRequestContextFactory` builds the transport-neutral context used by capabilities and adapters.
- A capability can be fully custom or adapter-backed. The adapter-backed path is a convenience pattern for commerce platforms, not a requirement.

## Outbound Webhook Flow

```mermaid
flowchart TD
    A["Order event in host app"] --> B["OrderWebhookPublisherInterface"]
    B --> C["Build webhook payload DTO"]
    C --> D["Resolve signing key"]
    D --> E["Add Content-Digest and RFC 9421 signature"]
    E --> F["Send HTTP request"]
    F --> G["WebhookDispatchResult"]
```

Notes:

- The shared SDK owns signing and delivery behavior.
- The host app or future platform plugin decides when a webhook should be sent.
- Transport-specific webhook triggers still belong to the host app or future platform plugin.

## Extension Model

```mermaid
flowchart LR
    A["Host app"] --> B["Register services"]
    B --> C["Capabilities"]
    B --> D["Payment handlers"]
    B --> E["Profile contributors"]
    B --> F["Signing key providers"]
    B --> G["Validators and enrichers"]
    C --> H["Shared SDK runtime"]
    D --> H
    E --> H
    F --> H
    G --> H
```

- Public extension points live in `Ucp\Sdk\Contract`, `Ucp\Sdk\Repository`, selected `Ucp\Sdk\Service` interfaces, and `Ucp\Sdk\Adapter`.
- Internal classes under `Ucp\Sdk\Internal` and bundle bridge code are not the place to build platform-specific behavior.

## What This SDK Does Not Own

```mermaid
flowchart LR
    A["Shared SDK"] --> B["REST protocol support"]
    A --> F["A2A protocol support"]
    A --> G["Embedded transport hooks"]
    A --> H["MCP profile metadata"]
    A --> C["Shared orchestration"]
    A -. not here .-> D["Shopware admin UI"]
    A -. not here .-> E["Shopware DAL definitions"]
    A -. not here .-> I["Shopware-specific MCP tools"]
```

- Keep transport support generic, configurable, and reusable.
- Put platform-specific product, cart, order, and payment mapping into platform plugins.
- For the future Shopware-specific wiring, see [shopware-plugin-blueprint.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/docs/shopware-plugin-blueprint.md).
