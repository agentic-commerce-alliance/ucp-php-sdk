# Ambiguous-schema fixture

A hand-written schema whose `oneOf` branches deliberately overlap, so
`GeneratedSchemaValidator`'s "matches N allowed schemas" message can be tested for what it
says rather than for whatever upstream happens to publish.

This used to be tested through `checkout.update.request`: at `2026-04-08`
`fulfillment_destination` was an untagged `oneOf` of a shipping address and a retail location,
and a destination carrying both `id` and `name` satisfied both. `2026-08-25` replaced that with
a tagged union (`type` plus `id`, with `if`/`then` per discriminator), so the ambiguity is gone
-- which is the point of a discriminator, and exactly why the test should not have depended on
it. At `2026-08-25` no `oneOf` in the generated set is reachably ambiguous: each is tagged,
disjoint by type, or has a branch that closes `additionalProperties`.
