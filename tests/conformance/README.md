# Conformance fixtures

What the upstream conformance suite is told about this repository's merchant example.

| File | Purpose |
| --- | --- |
| `conformance_input.json` | Protocol version, required capabilities, currency, and the item ids the suite drives: a normal item, one that is out of stock, and one that does not exist. |
| `test_fixtures.json` | Values the suite asserts against — the item's expected price, and the discount codes with their expected effect. |
| `enforced-modules.txt` | Modules CI blocks on. Empty until a module is actually green. |

These describe *our* merchant example, which is why they live here rather than being taken from
upstream's `test_data/flower_shop/`. `scripts/run-conformance.sh` copies them over the defaults
inside the checkout, because the suite's fixture paths are absl flags its `conftest.py` discards
(see `docs/conformance.md`).

Only capabilities this SDK actually has are declared. `dev.ucp.shopping.fulfillment` and
`dev.ucp.shopping.buyer_consent` appear in upstream's own fixture but are models under checkout
here, not registered capabilities, so declaring them would fail discovery for a reason that says
nothing about conformance.

Fixtures the merchant example cannot honour are **omitted rather than invented**: it models no
free-shipping threshold, no stored customers and no per-destination fulfillment options, so the
suite skips those tests instead of asserting against a fiction.
