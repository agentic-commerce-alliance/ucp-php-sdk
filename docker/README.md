# Docker

This folder contains the repo-local Docker setup.

Use it when the host machine does not already provide the right PHP toolchain.

Main files:

- [`../docker-compose.yml`](../docker-compose.yml) defines the `php` service.
- [`php/Dockerfile`](php/Dockerfile) builds the PHP 8.2 CLI image with the extensions needed by this repo.

For technical notes about why this setup exists and what it should contain, see [AGENTS.md](AGENTS.md).
