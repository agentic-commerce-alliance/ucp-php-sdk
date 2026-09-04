# Upstream

Questions and defects this SDK needs answered or fixed **upstream**, with the patches to fix
them where a patch is possible.

These are committed rather than kept locally because they are blockers on work in this
repository, and a reader wondering why `docs/conformance.md` reports 63 failures deserves to see
which of them are not ours. They are also how the next person avoids re-deriving the same
findings from the same failing suite.

| File | Target | State |
| --- | --- | --- |
| [conformance-suite-defects.md](conformance-suite-defects.md) | `Universal-Commerce-Protocol/conformance` | **not filed** |
| [spec-request-time-validation.md](spec-request-time-validation.md) | `Universal-Commerce-Protocol/ucp` | **not filed** |
| [conformance-suite-protocol-version.md](conformance-suite-protocol-version.md) | `Universal-Commerce-Protocol/conformance` | **not filed** |

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
