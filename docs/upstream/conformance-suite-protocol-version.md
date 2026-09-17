# The conformance suite cannot exercise UCP `2026-08-25`

**Target:** `Universal-Commerce-Protocol/conformance`
**Blocks:** this SDK's conformance lane at the protocol version it now serves.

## What is wrong

The suite's request and response models come from the `ucp-sdk` Python package, and
`pyproject.toml` pins it exactly:

```toml
dependencies = [
    ...
    "ucp-sdk==0.4.6",
]
```

`0.4.x` is the `2026-04-08` line. The commit that first pinned it says so outright — "Updates
`ucp-sdk` dependency version in `pyproject.toml` from `0.3.0` to `0.4.4` (matching the UCP
2026-04-08 specification)" (`c7b9a69`).

`Universal-Commerce-Protocol/python-sdk` released `v2026-08-25` on 2026-08-27, and that tag's
`pyproject.toml` declares `version = "0.5.0"`. So the models for the new version exist and the
suite has not adopted them.

## Since this was filed

The suite has moved twice, and both moves confirm rather than close this. `9021907` raised the
pin to `0.4.6` and switched the two destination construction sites to
`ShippingDestinationCreateRequest`/`UpdateRequest`; `016ecbc` pinned the CI checkout of
`python-sdk` to `v2026-04-08-6`, so what the suite runs against is now unambiguously the
`2026-04-08` line rather than whatever `main` happened to be
([#99](https://github.com/Universal-Commerce-Protocol/conformance/issues/99)). That is a
deliberate stay, not an oversight — which makes the ask below a decision someone has to take
rather than a backlog item.

The two moves do shrink the diff `0004` carries: the `*CreateRequest`/`*UpdateRequest` classes it
needed already exist in `0.5.0` under the same names and paths, so the patch now adds the
`type: "shipping_address"` discriminator to those calls rather than replacing the class.

## Why it matters here

The suite reads `ucp_version` from `conformance_input.json` and threads it through request
envelopes, defaulting to `2026-04-08`:

```python
version = self.conformance_config.get("ucp_version", "2026-04-08")
```

That plumbing is version-agnostic, so setting `2026-08-25` is accepted. What is not agnostic
are the Pydantic models the tests construct responses with — `checkout.Checkout(**response_json)`
and friends. A `2026-08-25` response carries shapes `0.4.x` does not describe: a structured
`description` object where it expects a string, a tagged fulfillment destination where it
expects an untagged address-or-location, `pan`/`network_token` credentials where it expects
`card` with a `card_number_type`.

So there is no revision of this suite that can assert `2026-08-25` behaviour, and pinning an
older one does not help: they are all further from it.

## What we want

Bump the dependency to `ucp-sdk>=0.5.0` (or a range spanning both) and let `ucp_version` select
which model set the assertions use. Until then the conformance lane can report only that a
`2026-08-25` server does not look like a `2026-04-08` one, which is true and useless.

## What we are doing meanwhile

`tests/conformance/conformance_input.json` declares `ucp_version: "2026-08-25"`, because that is
what this SDK serves and sending `2026-04-08` would fail version negotiation on every request —
a uniformly red lane that reports nothing about conformance.

`0004-adopt-ucp-sdk-0.5.0.patch` carries the port, applied by `scripts/run-conformance.sh` to the
pinned checkout. It was written after this was filed, once the measurement showed the port was
six import paths and one field rather than a rewrite: the lane needs a suite that can assert the
version this SDK serves, and waiting for upstream to cut one meant measuring nothing. Twelve
modules are enforced in CI on top of it.

It is a patch rather than a fork because it stays a proposal — the same diff offered on
[#104](https://github.com/Universal-Commerce-Protocol/conformance/issues/104), re-checked against
every pin bump. When upstream adopts `0.5.0` the patch stops applying, the runner fails loudly,
and this entry goes away.
