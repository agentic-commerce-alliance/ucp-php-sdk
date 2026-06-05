# Tools

This folder holds small maintenance scripts used by the repo.

Current tools:

- `build-public-api-snapshot.php` rebuilds the curated public API snapshot.
- `check-public-api-snapshot.php` compares the current snapshot with the expected one.
- `check-internal-class-references.php` scans internal and runtime concrete classes for missing references.
- `report-internal-coverage.php` summarizes coverage for the internal target bands.
- `internal-class-allowlist.php` holds reviewed exceptions for the internal reference scan.
- `public-api-snapshot.expected.txt` is the committed reference file.
- `public-api-snapshot.txt` is the generated working file.

For technical notes about how these tools fit into QA, see [AGENTS.md](AGENTS.md).
