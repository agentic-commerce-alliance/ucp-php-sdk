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

Baseline at the pinned commit, against the merchant example, at `ucp_version: 2026-08-25`:

**77 tests — 5 passed, 59 failed, 13 skipped.**

| Module | Failed |
| --- | ---: |
| `checkout_lifecycle_test` | 11 |
| `business_logic_test` | 7 |
| `webhook_structure_test` | 6 |
| `discount_test` | 5 |
| `totals_test` | 5 |
| `idempotency_test` | 4 |
| `order_test` | 4 |
| `validation_test` | 4 |
| `invalid_input_test` | 3 |
| `simulation_url_security_test` | 3 |
| `fulfillment_test` | 2 |
| `ap2_test`, `binding_test`, `card_credential_test`, `protocol_test`, `webhook_test` | 1 each |

Passing:

- `protocol_test::test_discovery`
- `business_logic_test::test_totals_calculation_on_create`
- `discount_test::test_client_applied_does_not_change_price`
- `validation_test::test_out_of_stock`
- `validation_test::test_structured_error_messages`

The 13 skips are honest: the merchant example models no free-shipping threshold, no stored
customers and no per-destination fulfillment options, so those fixtures are absent from
`tests/conformance/test_fixtures.json` and the suite skips them rather than inventing an answer.

### What the protocol-version switch changed

The lane previously ran at `2026-04-08` and reported **1 passed, 63 failed**. Serving
`2026-08-25` moved it to **5 passed, 59 failed** — and, more usefully, moved the wall. The
dominant failure used to be `capabilities_incompatible` on 59 of 63; now negotiation mostly
succeeds and **44 of the 59 failures are `KeyError: 'fulfillment'`**: the suite reads a
`fulfillment` object off checkout and order responses that the merchant example does not
populate. That is example-app work, not SDK work, and it is the next thing worth doing here.

One caveat on this number, recorded in
[upstream/conformance-suite-protocol-version.md](upstream/conformance-suite-protocol-version.md):
**the suite cannot actually assert `2026-08-25` behaviour.** It pins `ucp-sdk==0.4.4`, which is
the `2026-04-08` model set, and its newest commit predates the `2026-08-25` specification. The
`ucp_version` plumbing is version-agnostic, so the value is accepted and threaded into request
envelopes, but the Pydantic models the assertions build responses with are a version behind.
Some of the 59 are therefore model-shape mismatches rather than findings about this SDK. The
lane re-arms on its own: when the suite adopts `ucp-sdk>=0.5.0`, those disappear and what is
left is ours.

Sending `2026-04-08` instead was considered and rejected. This SDK no longer serves that
version, so every request would fail version negotiation and the lane would be uniformly red
while reporting nothing about conformance.

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
