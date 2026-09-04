# Conformance

The upstream [UCP conformance suite](https://github.com/Universal-Commerce-Protocol/conformance)
run against `examples/merchant-symfony-app`.

## Why this exists

Every other check in this repository validates the SDK against schemas it flattened itself,
using a validator it wrote, with tests written against its own routes. That is a closed loop,
and it stayed green while the SDK emitted DER signatures no conformant verifier could accept and
served `GET /ucp/v1/catalog/product/{id}` where the OpenAPI document has always said
`POST /catalog/product`.

The conformance suite is the only check here that this repository did not write. Its verdict is
the definition of "up to date" that matters.

## Running it

```bash
# Boots the merchant app, clones and installs the suite, runs everything.
./scripts/run-conformance.sh

# One module.
UCP_CONFORMANCE_MODULES=protocol_test.py ./scripts/run-conformance.sh

# Against a server you booted yourself.
UCP_CONFORMANCE_SKIP_SERVER=1 SERVER_URL=http://127.0.0.1:8081 ./scripts/run-conformance.sh
```

In CI, `.github/workflows/conformance.yml` does the same through Docker Compose. A JUnit report
lands in `var/reports/conformance/` and is uploaded as an artifact.

Requires Python 3.10+ on the host, or the `conformance` compose service.

## The environment the lane runs in

`prod`, with exactly one affordance turned on explicitly: `UCP_PROFILE_FETCHING_DEV_MODE=1`.

The suite serves its mock agent profile at `http://localhost:<port>` and cannot serve https,
while the SDK refuses plain-http profile fetching outside development mode -- correctly, since
a profile carries the keys used to verify every request from that platform. So a run against a
localhost mock cannot proceed without that mode, and it is set in the command that needs it
rather than inherited from `APP_ENV=dev`, so the one relaxation in play is visible and nothing
else about the dev container comes with it.

This used to be acquired by accident. `index.php` read `APP_ENV` from `$_SERVER` and `$_ENV`
only, and under PHP's built-in server an exported variable reaches neither, so `APP_ENV=prod`
silently resolved to `dev` and the whole run got the dev container -- which the runner's own
comment said must not happen. Every number reported from the lane before that was fixed was
measured in dev. In genuine prod with no affordances the lane scores **1 passed / 63 failed**,
because profile fetching is refused; with the single declared relaxation it scores the same
**58 passed / 6 failed** as before, which is also the evidence that dev mode was buying nothing
beyond the profile fetch.

## Two constraints worth knowing

**The suite and the merchant must share a localhost.** The suite runs a mock agent-profile
server and a mock webhook receiver on local ports, and hardcodes `http://localhost:<port>` into
the `UCP-Agent` header it sends. The merchant server has to resolve the same localhost, which is
why the compose service uses `network_mode: "service:merchant"` rather than compose networking.

**Its fixture paths are not configurable.** `--conformance_input` and `--fixture_config` are
absl flags, and the suite's `conftest.py` calls `FLAGS(["pytest"])`, which discards argv. Only
`SERVER_URL` is settable from the environment. `scripts/run-conformance.sh` therefore copies
`tests/conformance/*.json` over the defaults inside the checkout.

## Pinning

Upstream publishes no tags, so `.conformance-version` holds a **commit SHA**. Bumping it is a
deliberate act: the suite is the moving target this SDK is measured against, and a silent
upgrade would turn an unrelated pull request red.

## Where we stand

Baseline from a **clean clone**, twice with identical failure sets, on `ucp-sdk` 0.5.0:

**77 tests — 58 passed, 6 failed, 13 skipped.**

Twelve modules are green and **enforced** in CI via `tests/conformance/enforced-modules.txt`:
`ap2`, `binding`, `card_credential`, `checkout_lifecycle`, `discount`, `fulfillment`,
`idempotency`, `protocol`, `simulation_url_security`, `totals`, `validation`, `webhook`.
Running just that set exits 0 with 43 passed.

The lane applies `docs/upstream/*.patch` to the pinned checkout. Without them the suite cannot
pass against any merchant other than upstream's reference server, so a run without them measures
the patches rather than the merchant. Each patch is justified in
[upstream/conformance-suite-defects.md](upstream/conformance-suite-defects.md), and the runner
fails loudly if one stops applying -- that means upstream moved, which is a finding.

### The six that remain

| Failing | # | Why |
| --- | ---: | --- |
| `business_logic::test_buyer_consent` | 1 | Builds consent from the four `2026-04-08` booleans against a `2026-08-25` model; raises inside the suite before any request is sent. Needs a fixture rewrite upstream, not a patch to an assertion |
| `order`, `invalid_input` (adjustments) | 4 | `PUT /orders/{id}` and order adjustments. The upstream OpenAPI at `2026-08-25` defines only `GET /orders/{id}`, so implementing this means inventing a wire contract — its own slice, with its own design discussion |
| `webhook_structure::test_signature_covers_ucp_agent` | 1 | The webhook signature must cover `@authority`. The covered-component list is being made configurable in the signature lane (#140-#142); adding it here would collide |

### How it got here

| | Passed | Failed | Dominant failure |
| --- | ---: | ---: | --- |
| `2026-04-08` | 1 | 63 | `capabilities_incompatible` (59) |
| `2026-08-25`, no fulfillment | 5 | 59 | `KeyError: 'fulfillment'` (44) |
| fulfillment emitted | 18 | 45 | no single cluster |
| discounts, payment, order shape | 39 | 25 | — |
| webhooks, idempotency, negotiation, simulation | 53 | 11 | — |

What the last three steps found, in order of how much they were worth:

- **Discount codes were never read.** `HttpPayloadMapper::toDiscounts()` expected
  `[{"code": "X"}]`; every published schema says `codes` is a list of strings. Checkouts were
  priced as though no code had been sent.
- **Fulfillment was never emitted**, so nothing downstream of selecting an option could run.
- **A replayed idempotent response was not the response that was sent** — the envelope's empty
  `services` map came back as an empty list, because the store decoded to associative arrays
  and `{}` and `[]` are the same PHP value once flattened.
- **Nothing announced an order.** No webhook was dispatched at all, and once one was, it
  carried none of the Standard Webhooks identity headers and was never retried.
- **The `UCP-Agent` version parameter was ignored**, so a platform asking for a version this
  business does not serve was answered in the shapes of one it does.

### The dominant finding at `2026-04-08`, for the record

**59 of the 63 failures report the same thing:**

```
400 Requested operation is not included in the negotiated capability intersection.
```

The suite's mock agent profile declares exactly one capability, `dev.ucp.shopping.order`. This
SDK enforces negotiation *per operation* — `ShoppingOperationExecutor::assertNegotiated()`
refuses an operation whose capability is not in the intersection — so every checkout, cart and
catalog call is refused.

**This is a genuine conflict between the specification and the suite, and it is unresolved.**

The spec's *Request-Time Validation* section says a business **MUST** return
`capabilities_incompatible` when negotiation yields no mutually supported version *"for a
capability required by the requested operation"*. That is per-operation enforcement, and it is
what this SDK does — the issue that introduced it cites exactly that text.

The suite's mock agent profile declares one capability and then drives checkout, cart and
catalog while expecting them served. Both cannot be right.

Measured rather than assumed: relaxing the gate to fail only on an empty intersection takes the
suite from 1 passing to 5, so it is the first blocker rather than the whole chain — the other 58
fail again further along.

Not changed on a hunch. The suite already contains one demonstrable defect (below), so its
behaviour is not self-evidently the authority here; and the spec text is specific enough that
reversing enforcement would need more than a failing fixture to justify. This needs an upstream
answer. Tracked in `ucp-2026-08-25-upgrade.md`.

### What was fixed

The statuses. Both negotiation errors answered `400`; the spec's error-code table puts
`capabilities_incompatible` at **200** — a business outcome carried inside a UCP response with
`ucp.status: "error"` — and `version_unsupported` at **422**. That change takes the suite's
status mismatches from 62 to 0. The same tests still fail, now on response content, which is the
open question above rather than this one.

### One upstream defect

`protocol_test.py::ProtocolTest::test_version_negotiation` overwrites the `UCP-Agent` header
with a literal placeholder:

```python
headers["UCP-Agent"] = f'profile="..."; version="{advertised_version}"'
```

`...` is literal, not a URL. This SDK correctly answers `401 Platform profile URI must include
a host.` That is a bug in the suite rather than in this SDK, and it is worth reporting upstream.

## Upstream findings

Three defects in the suite itself, all with the same cause: the reference server it is validated
against (`Universal-Commerce-Protocol/samples`) does not fetch the platform profile for
capability negotiation and does not validate request payloads against the schemas, so the
suite's own inputs drifted from the spec without anything noticing.

1. **`protocol_test.py` sends a literal `profile="..."`.** It builds correct headers with
   `get_headers()`, then overwrites `UCP-Agent` to append `version=` and discards the URL. The
   branch asserts `[200, 201]`, so it cannot pass against a server that validates the profile
   URI.
2. **The mock agent profile declares one capability** (`dev.ucp.shopping.order`) while the suite
   exercises seven. Declaring what it exercises is compatible with both readings of the
   negotiation question above — an implementation that ignores capabilities is unaffected by a
   richer profile — which is why it is the change worth asking for regardless of how that
   question is settled.
3. **The fulfillment update payload omits `line_item_ids`**, which
   `types/fulfillment_method.json` annotates `ucp_request: {"update": "required"}`.
   `ensure_fulfillment_ready()` hand-builds that dict rather than using the pydantic model the
   create path uses, which is how the two drifted apart.

Measured against this SDK, at the pinned commit:

| | Failed | Passed |
| --- | ---: | ---: |
| unmodified | 63 | 1 |
| + fixes 1 and 2 | 59 | 5 |
| + fix 3 | 59 | 5 |

Fix 3 changes no counts but removes the schema rejection, so those tests fail further along. The
remaining failures are spread across modules as missing response fields rather than concentrated
on one refusal — most of what is left is ours, and now visible.

Written up with patches in [upstream/](upstream/), including which are filed and which are not.

## The strict-signature pass

`sh scripts/run-strict-signature-pass.sh`, and `docker compose run --rm strict-signature` in
CI, where it is **blocking**.

The default run uses `signature_policy: log`, which means it exercises **zero** signature
verification code. That is the same blind spot that let this SDK emit DER signatures no
conformant peer could verify while every gate in the repository was green, so a conformance
lane that never turns signatures on is missing the thing it was built for.

The upstream suite cannot cover it. It sends `request-signature: test`, and
`conformance_input.json` has no slot for a signing key -- its fields are `ucp_version`,
`required_capabilities`, `currency`, `items`, `out_of_stock_item` and `non_existent_item` --
so a strict run of the suite would refuse all 77 tests and prove only that strict refuses
everything. Adding that slot is an upstream change and is filed as such; until it lands, this
pass covers the policy.

Four checks, in `tests/conformance/strict_signature_pass.py`:

| Check | Why it is there |
| --- | --- |
| An unsigned request is refused with a UCP error descriptor | An agent that cannot parse the refusal cannot tell a signature problem from an outage, and retrying an unsigned request forever is what that produces |
| A correctly signed request is accepted | Otherwise strict mode is indistinguishable from an outage |
| A request whose covered components omit `content-digest` while carrying a body is refused | Dropping it from the covered set would otherwise be a body-swap primitive: the signature still verifies over method and target and nothing has attested to the bytes |
| A tampered signature is refused | So the acceptance above cannot be passing for any reason other than the signature actually being checked |

**The client signs, not the SDK.** It generates its own P-256 key, publishes the public half
as a JWK Set at a profile URL the merchant fetches, and assembles the RFC 9421 signature base
by hand. Driving the SDK's signer against the SDK's verifier would reproduce exactly the closed
loop this lane exists to break -- the two would agree about a base nobody else computes the
same way.

What it does not claim: two implementations agreeing is weaker than a published test vector,
and this one was written against the same RFC by the same hand as the code under test, so a
shared misreading would go unnoticed. The primitives are pinned separately against external
RFC 9421 fixtures. What this adds is that the *wiring* holds end to end over real HTTP --
routing, request listener, profile fetch and verifier, in the order a real request meets them.

## Promoting a module to blocking

`tests/conformance/enforced-modules.txt` lists the modules CI blocks on. It is empty today,
because a gate over a module that is not green is not a gate.

1. Get the module green locally.
2. Confirm it is green on `main` for ten consecutive runs.
3. Add it to `enforced-modules.txt`, and note the date here.

Promote **per module**, never all at once: `ap2_test` and `card_credential_test` depend on
surface this SDK deliberately does not ship, and waiting for them would mean never enforcing
anything.

## Deliberately not done

The suite documents out-of-stock as a `409`. This SDK has no exception that maps to 409 —
only `IdempotencyConflictException` does — so the merchant example reports it as `422`. Whether
the error model needs a business-conflict exception is a real question, and one the suite is
better placed to answer than a guess made in advance of it.
