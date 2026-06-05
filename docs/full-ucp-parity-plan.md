# Full UCP Parity Plan

## Summary

The SDK provides the shared transport/profile contract for `SwagAgenticCommerce`. It must describe REST, MCP, A2A, and embedded endpoints without hard-coding Shopware-specific routing into generic profile generation.

## Transport Model

- `Transport` supports `rest`, `mcp`, `a2a`, and `embedded`.
- `RuntimeConfiguration` and `ProfileBuildInput` carry the enabled transports.
- `transportEndpoints` can override default endpoint generation per transport.
- Default endpoint generation remains generic: `/ucp/v1`, `/ucp/mcp`, `/ucp/a2a`, and `/ucp/embedded`.
- REST remains the default enabled transport. A2A and embedded routes must return not found unless the transport is explicitly enabled in bundle config.
- Embedded responses must only allow configured agent origins.
- The Shopware plugin advertises client-facing MCP at `/ucp/mcp` on 6.7 once
  the core Store API MCP endpoint exists, then delegates internally to
  `/store-api/_mcp` with the sales-channel access key kept server-side.

## Shopware 6.7 MCP Dependency

The plugin does not ship a standalone MCP server. Its `/ucp/mcp` endpoint is a
proxy to the Shopware 6.7 core Store API MCP endpoint. MCP support depends on
that core endpoint existing with sales-channel context authentication. The SDK
should only provide reusable transport metadata and tool-registration
abstractions needed by the plugin.

## Validation

- Unit-test profile generation for all four transports.
- Verify endpoint overrides, especially Shopware's public MCP endpoint pointing
  to `/ucp/mcp` while internal proxying targets `/store-api/_mcp`.
- Keep REST behavior unchanged for existing users.
