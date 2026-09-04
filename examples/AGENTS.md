# Examples Agent Guide

This folder contains runnable sample apps for the SDK.

## Purpose

- `bootstrap-symfony-app` is the smallest useful integration sample.
- `merchant-symfony-app` is the richer reference app for realistic flows.

## Important Decisions

- Keep both apps plain Symfony. No Shopware code belongs here.
- The bootstrap app should stay small and easy to read.
- The merchant app can carry more realistic state, pricing, OAuth, and webhook examples.
- Integration tests in the bundle use both example apps as fixtures.

## The Merchant App Is A Conformance Target

Both external suites run against `merchant-symfony-app`, so its behaviour is read as the SDK's. A
shortcut here is reported as a protocol defect, and it takes a while to work out that it was the
example.

- **Never answer with a resource the app does not have.** Returning an empty cart carrying a
  `cart_not_found` warning looks helpful and is not: the answer *is* a cart, so an agent reading the
  status rather than the messages adds items to something the business does not have. It also fails
  response validation -- a fabricated cart has no totals -- so the caller receives
  `invalid_request` about our response instead of `not_found` about their id. Raise
  `ResourceNotFoundException`.
- **Read environment variables through one resolver that consults `getenv()`.** Under `php -S` an
  exported variable is in neither `$_ENV` nor `$_SERVER`, so reading only those two makes
  `APP_ENV=prod` resolve to `dev` -- which silently enabled development-mode profile fetching for
  every conformance run and invalidated the numbers.
- **Keep developer affordances explicit rather than derived from `APP_ENV`.** A conformance run
  needs exactly one relaxation (`UCP_PROFILE_FETCHING_DEV_MODE`, because the suites serve their mock
  agent profile over plain http on loopback). Getting it as a side effect of `dev` brings everything
  else with it, and then a pass means less than it appears to.

## What Not To Do

- Do not turn the bootstrap app into a production-like reference. That is the merchant app's job.
- Do not duplicate core or bundle logic here if the SDK can expose it through public contracts.

## Related Guides

- [bootstrap-symfony-app/AGENTS.md](bootstrap-symfony-app/AGENTS.md)
- [merchant-symfony-app/AGENTS.md](merchant-symfony-app/AGENTS.md)
