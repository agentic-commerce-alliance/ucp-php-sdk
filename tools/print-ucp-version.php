<?php

declare(strict_types=1);

// Prints the UCP protocol version this checkout serves, e.g. `2026-08-25`.
//
// Loads the enum directly rather than through Composer so a workflow can ask the question
// before -- or without -- `composer install`: the release title is derived from it, and the
// release-title guard runs on a bare checkout of the tag. The enum has no dependencies, which
// is what makes this safe; if it ever gains one, this script fails loudly rather than lying.
require __DIR__ . '/../packages/core/src/Enum/UcpProtocolVersion.php';

echo Ucp\Sdk\Enum\UcpProtocolVersion::current()->value, PHP_EOL;
