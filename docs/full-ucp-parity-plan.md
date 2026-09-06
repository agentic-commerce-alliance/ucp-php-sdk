# Full UCP Parity Plan

> **Superseded for the parity question.** The authoritative gap statement between
> this SDK and the UCP specification now lives in
> [ucp-2026-08-25-upgrade.md](ucp-2026-08-25-upgrade.md), which covers the
> protocol-version gap, the interop deviations, the conformance strategy and the
> sliced backlog.
>
> This document is kept for the one thing it still owns: the transport model and
> the decision that the SDK does **not** ship an MCP runtime.

## Transport Model

- `Transport` supports `rest`, `mcp`, `a2a`, and `embedded`.
- `RuntimeConfiguration` and `ProfileBuildInput` carry the enabled transports.
- `transportEndpoints` can override default endpoint generation per transport.
- Default endpoint generation remains generic for SDK-owned runtime transports: `/ucp/v1`, `/ucp/a2a`, and `/ucp/embedded`.
- MCP is metadata-only in the shared SDK and requires an explicit `mcp` transport endpoint supplied by the adopter.
- REST remains the default enabled transport. A2A and embedded routes must return not found unless the transport is explicitly enabled in bundle config.
- Embedded responses must only allow configured agent origins.
- The Shopware plugin advertises client-facing MCP at `/ucp/mcp` on 6.7 once
  the core Store API MCP endpoint exists, then delegates internally to
  `/store-api/_mcp` with the sales-channel access key kept server-side.

## Shopware MCP Dependency

The plugin does not ship a standalone MCP server. Its `/ucp/mcp` endpoint is a
proxy to the Shopware core Store API MCP endpoint. MCP support depends on that
core endpoint existing with sales-channel context authentication. The SDK should
only provide reusable transport metadata and tool-registration abstractions
needed by the plugin.

This decision still holds, and `SwagAgenticCommerce` has since implemented that
proxy (`src/Ucp/Mcp/Api/UcpMcpProxyController.php`) plus its own MCP tools — so
the runtime already exists one layer up. Building an MCP runtime here would
contradict this decision, require owning MCP session lifecycle and
streamable-HTTP transport inside a framework-free zero-dependency package, and
expose a second surface over the same operations REST already serves. The
reusable part is a tool-descriptor generator off the operation registry, not a
transport. See the "Explicitly out of scope" section of
[ucp-2026-08-25-upgrade.md](ucp-2026-08-25-upgrade.md).

## Validation

- Unit-test profile generation for all four transports.
- Verify endpoint overrides, especially Shopware's public MCP endpoint pointing
  to `/ucp/mcp` while internal proxying targets `/store-api/_mcp`.
- Keep REST behavior unchanged for existing users.
