# Bootstrap Symfony App Agent Guide

This app is the minimal reference for bundle wiring.

## Current Shape

- `src/Kernel.php`
  wires the bundle with app-local SQLite storage
- `src/Demo`
  contains small capability implementations and hook examples
- runtime state stays local to this app
- `var`
  is runtime-only and ignored by Git

## What It Demonstrates

- discovery profile publishing
- catalog and cart capabilities
- checkout and order-read wiring
- tokenization
- OAuth identity linking
- checkout validators and augmenters
- payment mandate verification
- order webhook enrichment

## Intentional Limits

- data stays simple and mostly static or in-memory style
- this app is not the realistic merchant reference
- do not grow this app into a fake commerce platform
- do not commit generated `var/cache`, `var/log`, or SQLite files from this app

## External Setup Notes

- This app depends on the root workspace `vendor` directory.
- There is no app-local Composer project here.
- For first run, install dependencies at the repo root, then start `php -S` with `examples/bootstrap-symfony-app/public` as the document root.

## Related Guide

- [../merchant-symfony-app/README.md](/Users/b.meyer/Documents/Projects/ucp-php-sdk/examples/merchant-symfony-app/README.md)
