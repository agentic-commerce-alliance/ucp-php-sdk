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

Baseline at the pinned commit, against the merchant example:

**77 tests — 1 passed, 63 failed, 13 skipped.**

| Module | Failed |
| --- | ---: |
| `checkout_lifecycle_test` | 11 |
| `business_logic_test` | 8 |
| `discount_test` | 6 |
| `validation_test` | 6 |
| `webhook_structure_test` | 6 |
| `totals_test` | 5 |
| `idempotency_test` | 4 |
| `order_test` | 4 |
| `invalid_input_test` | 3 |
| `simulation_url_security_test` | 3 |
| `fulfillment_test` | 2 |
| `ap2_test`, `binding_test`, `card_credential_test`, `protocol_test`, `webhook_test` | 1 each |

The 13 skips are honest: the merchant example models no free-shipping threshold, no stored
customers and no per-destination fulfillment options, so those fixtures are absent from
`tests/conformance/test_fixtures.json` and the suite skips them rather than inventing an answer.

### The dominant finding

**59 of the 63 failures report the same thing:**

```
400 Requested operation is not included in the negotiated capability intersection.
```

The suite's mock agent profile declares exactly one capability, `dev.ucp.shopping.order`. This
SDK enforces negotiation *per operation* — `ShoppingOperationExecutor::assertNegotiated()`
refuses an operation whose capability is not in the intersection — so every checkout, cart and
catalog call is refused.

The specification does not appear to require that. It defines a negotiation failure as the
intersection being **empty** ("the provided profile is valid but capability intersection is
empty or versions are incompatible"), and the intersection here is not empty. What the
intersection governs, per *Response Capability Selection*, is which capabilities a business
declares in `ucp.capabilities` — not which requests it will answer.

Measured rather than assumed: relaxing the gate to fail only on an empty intersection takes the
suite from 1 passing to 5. So it is the **first** blocker rather than the whole chain — the
other 58 fail again further along — but nothing else can be assessed until it moves.

Tracked as its own task; see `ucp-2026-08-25-upgrade.md`.

### One upstream defect

`protocol_test.py::ProtocolTest::test_version_negotiation` overwrites the `UCP-Agent` header
with a literal placeholder:

```python
headers["UCP-Agent"] = f'profile="..."; version="{advertised_version}"'
```

`...` is literal, not a URL. This SDK correctly answers `401 Platform profile URI must include
a host.` That is a bug in the suite rather than in this SDK, and it is worth reporting upstream.

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
