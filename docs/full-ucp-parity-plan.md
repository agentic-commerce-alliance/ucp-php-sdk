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

## MCP Runtime Boundary

The shared SDK publishes MCP profile metadata but does not implement an MCP server.
Adopters provide the runtime, authentication, session lifecycle and streamable-HTTP
transport, and configure its public endpoint through `transportEndpoints`.

Operations should continue through the shared capability layer so additional
transports reuse negotiation, payload mapping and validation. A reusable tool-descriptor
generator can derive metadata from the operation registry without adding a transport.
See the "Explicitly out of scope" section of
[ucp-2026-08-25-upgrade.md](ucp-2026-08-25-upgrade.md).

## Validation

- Unit-test profile generation for all four transports.
- Verify that adopter-supplied MCP endpoint overrides appear in the public profile.
- Keep REST behavior unchanged for existing users.
