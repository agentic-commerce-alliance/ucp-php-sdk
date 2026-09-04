# The conformance suite cannot exercise UCP `2026-08-25`

**Target:** `Universal-Commerce-Protocol/conformance`
**Blocks:** this SDK's conformance lane at the protocol version it now serves.

## What is wrong

The suite's request and response models come from the `ucp-sdk` Python package, and
`pyproject.toml` pins it exactly:

```toml
dependencies = [
    ...
    "ucp-sdk==0.4.4",
]
```

`0.4.4` is the `2026-04-08` line. The commit that set it says so outright — "Updates `ucp-sdk`
dependency version in `pyproject.toml` from `0.3.0` to `0.4.4` (matching the UCP 2026-04-08
specification)" (`c7b9a69`).

`Universal-Commerce-Protocol/python-sdk` released `v2026-08-25` on 2026-08-27, and that tag's
`pyproject.toml` declares `version = "0.5.0"`. So the models for the new version exist; the
suite has not adopted them. Its newest commit, `fdbdafd`, is dated **2026-08-18** — a week
before the `2026-08-25` specification was published, and nine days before the SDK that
implements it.

## Why it matters here

The suite reads `ucp_version` from `conformance_input.json` and threads it through request
envelopes, defaulting to `2026-04-08`:

```python
version = self.conformance_config.get("ucp_version", "2026-04-08")
```

That plumbing is version-agnostic, so setting `2026-08-25` is accepted. What is not agnostic
are the Pydantic models the tests construct responses with — `checkout.Checkout(**response_json)`
and friends. A `2026-08-25` response carries shapes `0.4.4` does not describe: a structured
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
a uniformly red lane that reports nothing about conformance. The lane stays advisory with an
empty `tests/conformance/enforced-modules.txt`, and it re-arms on its own: when the suite adopts
`0.5.0`, the model-shape failures disappear and what remains is ours.

No patch is offered here. Changing a dependency pin is a one-line edit that upstream should make
deliberately when it cuts a `2026-08-25` revision of the suite, not something to carry as a
local diff.
