# Is request-time capability enforcement normative? The reference server does not implement it

*Filed against the spec repository; the companion suite issue is separate.*

## The text

*Discovery, Governance, and Negotiation → Version Selection → Request-Time Validation*:

> 3. If capability negotiation yields no mutually supported version **for a capability required
>    by the requested operation**, the business **MUST** return a `capabilities_incompatible`
>    error.

Read plainly, a business must refuse an operation whose capability the platform did not declare.
We implemented it that way.

## What the reference does

`Universal-Commerce-Protocol/samples` does not:

- it performs **version** negotiation only (`_validate_ucp_headers`);
- it fetches the platform profile **only for signature key discovery**, and only when a
  `Signature-Input` header is present — *"No profile fetch occurs unless a `Signature-Input`
  header is present, so unsigned traffic incurs no extra work"*;
- it never computes a per-request capability intersection. `capabilities_incompatible` does not
  appear anywhere in the repository;
- a code comment describes `ucp` in a request as *"usually just version negotiation info"*.

The conformance suite, which is validated against that server, ships a mock agent profile
declaring a single capability and then exercises checkout, cart, catalog and discount.

So the spec says `MUST`, and the reference implementation and the conformance suite both assume
the opposite. An implementer following the text fails the suite; one following the reference
ignores a `MUST`.

## Why it matters beyond our own test results

The ambiguity decides whether a platform is obliged to enumerate, in its profile, every
capability it intends to call. That is a real interoperability contract:

- If **yes**, platforms with incomplete profiles will be refused by conformant businesses, and
  the reference should be corrected so implementers do not learn the wrong behaviour from it.
- If **no**, then the intersection governs only *Response Capability Selection* — which
  capabilities a business declares in `ucp.capabilities` — and step 3 should say so, because as
  written it reads as a request-admission rule.

Adjacent text pulls the other way and is part of why this is confusing. *Error Handling*
describes a negotiation failure as *"the provided profile is valid but capability intersection
is empty or versions are incompatible"* — **empty**, not "missing the one this operation needs".
Under that sentence a profile sharing any capability is negotiable and the request proceeds.
Step 3 and that sentence do not describe the same rule.

## What would resolve it

Either:

1. Confirm step 3 is normative, and open a corresponding issue against `samples` — in which
   case the suite's mock profile also needs to declare what it exercises; or
2. Narrow step 3 to version incompatibility for an already-intersecting capability, and state
   explicitly that a capability absent from the platform profile does not bar the operation.

Either way the two passages should agree.

## Context

`agentic-commerce-alliance/ucp-php-sdk`, an independent PHP implementation of the merchant side
at `2026-04-08`, currently implements reading (1). Against the unmodified suite that produces 59
of 63 failures, all `capabilities_incompatible`. We have not changed the behaviour, because the
spec text is specific and we would rather have the answer than guess.
