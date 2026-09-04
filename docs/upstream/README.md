# Upstream

Questions and defects this SDK needs answered or fixed **upstream**, with the patches to fix
them where a patch is possible.

These are committed rather than kept locally because they are blockers on work in this
repository, and a reader wondering why `docs/conformance.md` reports 63 failures deserves to see
which of them are not ours. They are also how the next person avoids re-deriving the same
findings from the same failing suite.

| File | Target | State |
| --- | --- | --- |
| [conformance-suite-defects.md](conformance-suite-defects.md) | `Universal-Commerce-Protocol/conformance` | **filed** — [#101](https://github.com/Universal-Commerce-Protocol/conformance/issues/101), [#102](https://github.com/Universal-Commerce-Protocol/conformance/issues/102), [#103](https://github.com/Universal-Commerce-Protocol/conformance/issues/103) |
| [spec-request-time-validation.md](spec-request-time-validation.md) | `Universal-Commerce-Protocol/ucp` | **filed** — [#805](https://github.com/Universal-Commerce-Protocol/ucp/issues/805) |
| [conformance-suite-protocol-version.md](conformance-suite-protocol-version.md) | `Universal-Commerce-Protocol/conformance` | **filed** — [#104](https://github.com/Universal-Commerce-Protocol/conformance/issues/104); 0.5.0 works, patch `0004` |

Seven `.patch` files are the proposed fixes for the conformance suite. `scripts/run-conformance.sh`
applies all of them to the pinned checkout, so the lane measures this SDK rather than the state of
someone's working tree. Applied in order they take the suite from 1 passed / 63 failed to
58 passed / 6 failed against the merchant example.

The `.patch` files are the proposed fixes for the conformance suite. Apply them to a
checkout of that repository, not to this one:

```bash
git -C var/conformance apply ../../docs/upstream/000*.patch
UCP_CONFORMANCE_NO_CHECKOUT=1 ./scripts/run-conformance.sh
```

`UCP_CONFORMANCE_NO_CHECKOUT` stops the runner resetting the checkout to the pinned commit,
which would otherwise discard them.

## Keeping this honest

Update the **State** column when something is filed, and delete the entry once it is resolved
upstream and the pin in `.conformance-version` has moved past it. A stale entry here is worse
than none: it invites someone to re-file a question that already has an answer.
