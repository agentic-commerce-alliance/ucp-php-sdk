# Three defects in the conformance suite, with patches

Found while running the suite against a PHP merchant implementation
([ucp-php-sdk](https://github.com/agentic-commerce-alliance/ucp-php-sdk)). All three share a
cause: the reference server the suite is validated against does not fetch the platform profile
for capability negotiation and does not validate request payloads against the schemas, so the
suite's own inputs have drifted from the spec without anything noticing.

Each is independent and has a patch attached.

---

## 1. `protocol_test.py` sends a literal `profile="..."`

`test_version_negotiation` builds correct headers with `get_headers()` and then overwrites
`UCP-Agent` purely to append `version=`, discarding the profile URL:

```python
headers = integration_test_utils.get_headers()
headers["UCP-Agent"] = f'profile="..."; version="{advertised_version}"'
```

`...` is a literal, not a URL. This is the *"Compatible Version"* branch, which asserts
`[200, 201]`, so it can never pass against a server that validates the profile URI. Ours answers
`401 Platform profile URI must include a host`.

It survives today because the reference server only dereferences the profile when a
`Signature-Input` header is present, so an unusable profile URL is never noticed.

**Patch:** `0001-protocol-test-real-profile-url.patch` — keep the URL `get_headers()` already
built and append the version to it.

---

## 2. The mock agent profile declares one capability but the suite exercises seven

`shopping-agent-test.json` declares only `dev.ucp.shopping.order`, while the suite drives
checkout, cart, catalog and discount and expects them served.

For a business that implements request-time capability negotiation, that is a refusal. The spec
is explicit about it under *Discovery, Governance, and Negotiation → Version Selection →
Request-Time Validation*:

> 3. If capability negotiation yields no mutually supported version **for a capability required
>    by the requested operation**, the business **MUST** return a `capabilities_incompatible`
>    error.

Against our implementation this produced **59 of 63 failures**, all reporting
`capabilities_incompatible`.

The reference server never reaches that path — it does version negotiation only, fetches the
profile solely for signature key discovery, and the string `capabilities_incompatible` does not
appear anywhere in `Universal-Commerce-Protocol/samples`. So the fixture and the reference agree
with each other, and neither agrees with the spec text.

**This patch does not take a position on that.** A profile that declares the capabilities being
exercised passes whether or not a business enforces negotiation — an implementation that ignores
capabilities is unaffected by a richer profile. It is the change that lets both kinds of
implementation run the suite.

Separately, it is worth deciding whether *Request-Time Validation* step 3 is normative. If it
is, the reference server does not implement it. If it is not, the `MUST` should be softened,
because implementers are reading it. We implemented it because that text says `MUST`.

**Patch:** `0002-agent-profile-declare-exercised-capabilities.patch` — declare catalog search
and lookup, cart, checkout, discount, fulfillment and order, with `extends` set on the two
extensions.

---

## 3. The fulfillment update payload omits `line_item_ids`, which the schema requires on update

`integration_test_utils.ensure_fulfillment_ready()` builds the update payload as a hand-written
dict rather than through the pydantic model the create path uses:

```python
method_payload = {
  "type": "shipping",
  "destinations": [address],
  "selected_destination_id": "dest_default",
}
```

`shopping/types/fulfillment_method.json` annotates `line_item_ids` as
`ucp_request: {"create": "optional", "update": "required"}`, so this payload is invalid for the
operation it is sent to. `fulfillment_structure_test.py` sends it correctly; the shared helper
does not, which is what the two code paths differing allowed.

After fixing 1 and 2, this accounted for **41 of the remaining failures** against our
implementation, which validates requests against the published schemas.

**Patch:** `0003-update-payload-line-item-ids.patch` — carry the checkout's line item ids.

---

## Effect, measured

Against our implementation, at pinned commit `fdbdafd`:

| | Failed | Passed |
| --- | ---: | ---: |
| unmodified | 63 | 1 |
| + patches 1 and 2 | 59 | 5 |
| + patch 3 | 59 | 5 |

Patch 3 does not change the counts but removes the schema rejection, so the affected tests fail
further along instead. The remaining failures are spread across modules as missing response
fields rather than concentrated on one refusal, which is the difference between "one wall" and
"a list of real gaps on our side" — most of what is left is ours to fix, and we can now see it.

## Context

Running against: `agentic-commerce-alliance/ucp-php-sdk`, an independent PHP implementation of
the merchant side, at protocol version `2026-04-08`. Happy to supply full logs or run any
variation of these against it.

---

## 4. Three modules hardcode a payment handler instead of reading the fixture

**Tests:** `ap2_test::test_ap2_mandate_completion`,
`binding_test::test_token_binding_completion`,
`card_credential_test::test_card_credential_payment`

`integration_test_utils.get_valid_payment_payload()` reads the instrument — including its
`handler_id` — from `test_data/<fixture>/payment_instruments.csv`, which is how a merchant
under test declares the handler it implements. But `ap2_test.py:48`, `binding_test.py:51` and
`card_credential_test.py:48` each build their own payload with a literal:

```python
"handler_id": "mock_payment_handler",
```

A business is required to reject an instrument for a handler it does not support, so any
merchant that is not the reference server fails these three on a value it was never given a
way to configure. Rejecting is the correct behaviour; the literal is the defect.

**Fix:** read the handler from `test_data.payment_instruments` as the shared helper does.

## 5. The idempotency conflict payload is a no-op for merchants trading in EUR

**Test:** `idempotency_test::test_idempotency_create`

The test sends a request, replays it, and then sends a "conflicting" one under the same
idempotency key, expecting `409`:

```python
conflict_payload = create_payload.model_copy(deep=True)
conflict_payload.currency = "EUR"
```

The original payload's currency comes from `conformance_input.json`. For a merchant that
declares `EUR` — this one is a German business — the conflict payload is byte-identical to the
original, so a correct implementation answers `201` by replaying, exactly as the previous
assertion required. The test can only pass for a merchant that does not trade in EUR.

**Fix:** derive the conflicting value from the configured currency rather than hardcoding one,
e.g. `"USD" if configured == "EUR" else "EUR"`.

## 6. `test_fulfillment_flow` assumes the business charges no tax

**Test:** `fulfillment_test::test_fulfillment_flow`

```python
expected_total = self.fixture_ctx.get_test_price() + option_cost
```

This asserts the grand total is the item price plus shipping, which holds only where nothing
else is added. `totals_test` names `tax` as a valid additive entry and `business_logic_test`
verifies totals by summing every non-`total` entry — so the suite models tax correctly
elsewhere and contradicts itself here. A merchant charging 19% VAT fails this one test while
passing the arithmetic checks in the other two.

**Fix:** sum the entries as `business_logic_test` does, rather than assuming which of them
exist.
