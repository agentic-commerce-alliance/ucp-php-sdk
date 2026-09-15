# Local testing

How to make UCP requests against a shop you are running yourself, why the obvious ways fail,
and when this page is not enough.

## The problem: every request names an agent

A UCP runtime request is never anonymous. It carries a `UCP-Agent` header whose `profile`
parameter is the URL of the *calling platform's* profile document:

```http
UCP-Agent: platform; profile="https://agent.example.com/.well-known/ucp"
```

The shop fetches that document and reads three things from it before answering anything: the
agent's public keys (to verify signatures), the agent's capabilities (to negotiate what the
request may do) and the agent's protocol version (which must match the one the shop serves).
Fetching a URL supplied by the caller is a server-side request on untrusted input, so the SDK
applies strict rules to it:

- `https` only, except plain `http` for the hosts `localhost`, `127.0.0.1` and `::1`, and only
  while `profile_fetching_development_mode` is on;
- port 443 or 8443 for anything that is not one of those three hosts;
- the host must resolve to a public address. Loopback, private and link-local addresses are
  refused, which rules out `*.localhost` names, container hostnames such as
  `host.docker.internal`, and `/etc/hosts` aliases;
- the host must be on `allowed_profile_hosts`. An empty list admits only the three local
  names, and only in development mode.

Nothing a laptop can offer passes all of that, so the first request against a fresh install
used to require a second web server whose only job was to hand out a JSON document. Most
people found that out from the error message.

## The shortcut: your shop is its own agent

In development mode the SDK accepts the shop's **own** discovery URL as the agent profile:

```yaml
# config/packages/ucp_sdk.yaml — local development only
ucp_sdk:
    profile_fetching_development_mode: true
```

A request whose `UCP-Agent` profile points at this deployment's `/.well-known/ucp` is then
answered with the deployment's own profile, built in-process exactly as the discovery endpoint
would serve it. Nothing is fetched and nothing is cached. The match is deliberately narrow: the
path must be `/.well-known/ucp`, and the scheme, host and port must equal the request's own or
the configured `base_uri`. Any other URL, on the own host or elsewhere, takes the normal fetch
path with every check above.

Development mode also admits plain `http` and loopback profile hosts in general. It is a
statement that this deployment is not reachable from the internet. Never enable it in
production.

### Let the bundle write the request

```bash
bin/console ucp:dev:request                       # list every operation
bin/console ucp:dev:request catalog.search        # print one request
bin/console ucp:dev:request cart.create --id=sku-1
```

The command prints a `curl` with the `UCP-Agent` header pointing at your own profile, an
`Idempotency-Key` for write operations, and the smallest body the request schema accepts. Paste
it into a shell. It warns when development mode is off, because the request would then be
refused with `Platform profile host is not allowed by the current runtime configuration.`

A worked session against the bootstrap example:

```bash
UCP_BASE_URI=http://127.0.0.1:8080 php -S 127.0.0.1:8080 -t examples/bootstrap-symfony-app/public &
curl -s http://127.0.0.1:8080/.well-known/ucp | head -c 400
bin/console ucp:dev:request catalog.search --base-uri=http://127.0.0.1:8080
# paste the printed curl; expect a 200 with "status": "success" in the ucp envelope
```

### What this proves, and what it does not

It exercises everything that runs *after* the profile is known: version check, capability
negotiation, request and response schema validation, your capability code, and the response
envelope. That is the part adopters get wrong and the part worth iterating on locally.

It does not exercise signature verification. The shop's own key set is now the agent's key set,
and requests you paste are unsigned, so `signature_policy: log` records them as unverified and
`strict` would refuse them. It also does not exercise the profile fetch, the profile cache, or
the URL safety rules, since none of them run on this path.

## When you need a real second profile

- **Strict signatures.** The agent must sign with a key published in *its* profile. Serve a
  small profile document from a local process, for example `php -S 127.0.0.1:9912` in a
  directory holding `agent.json`, and reference it as `http://localhost:9912/agent.json`. The
  host must be exactly `localhost`, `127.0.0.1` or `::1`, development mode must be on, and
  `allowed_profile_hosts` must be empty or contain `localhost`. Sign requests per RFC 9421 with
  the matching private key.
- **The upstream conformance suite.** It brings its own agent profile and signing keys and
  needs the same local-host arrangement. `scripts/run-conformance.sh` does all of it; see
  [conformance.md](conformance.md).
- **A real platform against a staging shop.** Then the platform hosts its profile on a public
  `https` host on port 443 or 8443, and you put that host on `allowed_profile_hosts`. No
  development mode.

## Pitfalls

- **`Plain http is only allowed for local development hosts.`** The profile host is a
  `.localhost` name, a container name or an alias. Only the three literal local names qualify.
  Use the own-profile shortcut, or serve the profile on `localhost:<port>`.
- **`Profile host "…" resolves to a blocked IP address.`** Same cause over `https`: the name
  resolves to loopback or a private range.
- **`Platform profile host is not allowed by the current runtime configuration.`**
  Development mode is off, or the host is not on `allowed_profile_hosts`. In production this
  is the correct answer for an unknown platform.
- **A changed agent profile is not picked up.** Fetched profiles are cached according to their
  `Cache-Control`, between 60 seconds and `platform_profile_cache_ttl`. Delete the row from
  `ucp_platform_profile_cache`, or wait. The own-profile shortcut has no cache.
- **`Idempotency-Key` missing.** Write operations require one when `idempotency_required` is
  on. `ucp:dev:request` adds a fresh one to every non-GET request.
- **`422 version_unsupported`.** The agent profile names a UCP version this release does not
  serve. The SDK serves exactly one version per release; see
  [ucp-2026-08-25-upgrade.md](ucp-2026-08-25-upgrade.md) for the current one.
