# Security Policy

This repository contains a security-sensitive SDK. Please do not report suspected vulnerabilities through public GitHub issues.

## Supported Versions

Current supported line:

- `0.0.x` alpha and beta tags on the active mainline

Because the project is still pre-`1.0`, fixes may land in the newest pre-release tag rather than being backported broadly.

## How To Report A Vulnerability

Preferred channel:

- GitHub Private Vulnerability Reporting for this repository, if it is enabled

If private vulnerability reporting is not available yet:

- do not open a public issue
- contact the maintainers through an existing private channel before disclosure

Please include:

- affected package:
  - `shopware/ucp-php-sdk-core`
  - `ucp-php-sdk/symfony-bundle`
- affected version or commit
- impact summary
- reproduction steps or proof of concept
- any suggested mitigation if you have one

## What To Expect

- initial acknowledgement target: within 5 business days
- follow-up may request more detail, a reproducer, or environment information
- public disclosure should wait until a fix or mitigation is available

## Scope Notes

In scope for this repository:

- shared SDK protocol handling
- signing, replay protection, idempotency, and OAuth state handling
- Symfony bundle HTTP wiring and default storage adapters

Out of scope for this repository:

- MCP implementations
- Shopware admin UI or DAL specifics that are not part of this shared SDK
- vulnerabilities in downstream applications that misuse the SDK

## Operational Reminder

If you operate this SDK yourself:

- run the full QA gate before release
- create signing keys before enabling outbound webhooks
- use `ucp:storage:cleanup` regularly to purge expired retained state
