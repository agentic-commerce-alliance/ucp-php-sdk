<?php

declare(strict_types=1);

/**
 * Notices that upstream moved, which nothing else in this repository does.
 *
 * The `2026-08-25` release sat unnoticed for weeks. Every gate here answers "is this repository
 * self-consistent", and the answer stayed yes the whole time -- the pinned schemas matched the
 * generated ones, the tests passed, and the protocol had moved on without us. This is the check
 * whose question is about the outside world.
 *
 * Two independent things can drift, and they need different answers:
 *
 *   1. Upstream published something we have not pinned. Someone decides whether to adopt it.
 *   2. What upstream serves at a tag we already pinned has changed. Nobody decided that; a tag
 *      moved under us, and the pinned copy is no longer the thing it claims to be.
 *
 * The second is the quieter and worse one, which is why it is checked rather than assumed.
 */
const REPO_UCP = 'Universal-Commerce-Protocol/ucp';
const REPO_CONFORMANCE = 'Universal-Commerce-Protocol/conformance';

$root = dirname(__DIR__);
$findings = [];

$findings = array_merge($findings, checkProtocolReleases($root));
$findings = array_merge($findings, checkConformancePin($root));
$findings = array_merge($findings, checkPinnedTreesMatchTheirTags($root));

if ($findings === []) {
    echo "No spec drift detected.\n";
    exit(0);
}

// Written to stdout as Markdown so the workflow can put it straight into an issue body. The
// exit code is what makes it visible; the text is what makes it actionable.
echo "## Spec drift detected\n\n";
foreach ($findings as $finding) {
    echo '- ' . $finding . "\n";
}
echo "\nRaised by `tools/check-spec-drift.php`. Close this once the pins are updated or the\n";
echo "difference is deliberate and recorded.\n";

exit(1);

/**
 * @return list<string>
 */
function checkProtocolReleases(string $root): array
{
    $known = knownProtocolVersions($root);
    $releases = githubTags(REPO_UCP);

    if ($releases === null) {
        return ['Could not read releases for `' . REPO_UCP . '`; drift is unknown rather than absent.'];
    }

    // Only versions *newer* than the newest we know are drift. A superseded release we never
    // pinned is not something anyone needs to act on, and reporting it would make this check
    // permanently red -- which is the state in which a check stops being read.
    $newest = max($known);
    $ahead = [];

    foreach ($releases as $tag) {
        // Release tags are `vYYYY-MM-DD`; anything else is not a protocol version. The format
        // sorts correctly as a string, which is why no date parsing is needed here.
        if (preg_match('/^v(\d{4}-\d{2}-\d{2})$/', $tag, $matches) !== 1) {
            continue;
        }

        if ($matches[1] > $newest) {
            $ahead[] = $matches[1];
        }
    }

    if ($ahead === []) {
        return [];
    }

    sort($ahead);

    return [sprintf(
        'UCP **%s** %s published upstream; this SDK serves %s. Adopting one is a decision, not a bump: see `docs/ucp-2026-08-25-upgrade.md` for what the last one involved.',
        implode('**, **', $ahead),
        count($ahead) === 1 ? 'is' : 'are',
        $newest,
    )];
}

/**
 * @return list<string>
 */
function checkConformancePin(string $root): array
{
    $pinFile = $root . '/.conformance-version';
    if (! is_file($pinFile)) {
        return [];
    }

    $pinned = trim((string) file_get_contents($pinFile));
    $head = githubDefaultBranchSha(REPO_CONFORMANCE);

    if ($head === null) {
        return ['Could not read the head commit of `' . REPO_CONFORMANCE . '`.'];
    }

    if ($head === $pinned) {
        return [];
    }

    // Not a failure in itself -- the suite moves constantly and pinning is deliberate. It is
    // reported so the gap is a decision someone made rather than one that made itself.
    return [sprintf(
        'The conformance suite has moved: pinned `%s`, upstream head `%s`. See `docs/conformance.md` before bumping.',
        substr($pinned, 0, 12),
        substr($head, 0, 12),
    )];
}

/**
 * The check nothing else performs: is the pinned copy still what the tag serves?
 *
 * `composer sync:verify` proves the generated schemas follow from the pinned copy. It cannot
 * prove the pinned copy still follows from upstream, because it never looks upstream. A retag
 * would leave both green while the artifacts describe a specification that no longer exists.
 *
 * @return list<string>
 */
function checkPinnedTreesMatchTheirTags(string $root): array
{
    $findings = [];

    foreach (glob($root . '/packages/core/resources/schema/pinned/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $version = basename($directory);
        $checkout = sys_get_temp_dir() . '/ucp-spec-drift-' . $version;

        run(sprintf('rm -rf %s', escapeshellarg($checkout)));
        $cloned = run(sprintf(
            'git clone --quiet --depth 1 --branch %s https://github.com/%s.git %s',
            escapeshellarg('v' . $version),
            REPO_UCP,
            escapeshellarg($checkout),
        ));

        if (! $cloned) {
            $findings[] = sprintf('Could not clone `v%s`; the tag may have been deleted or renamed.', $version);

            continue;
        }

        $upstream = $checkout . '/source';
        if (! is_dir($upstream)) {
            $findings[] = sprintf('`v%s` no longer has a `source/` tree; the upstream layout changed.', $version);
            run(sprintf('rm -rf %s', escapeshellarg($checkout)));

            continue;
        }

        $differences = pinnedDifferences($directory, $upstream);
        if ($differences !== []) {
            $findings[] = sprintf(
                "`v%s` no longer matches what is pinned -- the tag moved. %d file(s) differ, first few:\n  - %s",
                $version,
                count($differences),
                implode("\n  - ", array_slice($differences, 0, 5)),
            );
        }

        run(sprintf('rm -rf %s', escapeshellarg($checkout)));
    }

    return $findings;
}

/**
 * Compares by content hash rather than by `git diff`, because the pinned tree is a mirror of a
 * subset and a directory-level diff would report every file upstream has that we never mirror.
 *
 * @return list<string>
 */
function pinnedDifferences(string $pinned, string $upstream): array
{
    $differences = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pinned, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'json') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($pinned) + 1);
        $counterpart = $upstream . '/' . $relative;

        if (! is_file($counterpart)) {
            $differences[] = $relative . ' (gone upstream)';

            continue;
        }

        if (hash_file('sha256', $file->getPathname()) !== hash_file('sha256', $counterpart)) {
            $differences[] = $relative . ' (content changed)';
        }
    }

    sort($differences);

    return $differences;
}

/**
 * @return list<string>
 */
function knownProtocolVersions(string $root): array
{
    require_once $root . '/packages/core/src/Enum/UcpProtocolVersion.php';

    return Ucp\Sdk\Enum\UcpProtocolVersion::knownVersions();
}

/**
 * @return list<string>|null
 */
function githubTags(string $repository): ?array
{
    $payload = githubApi(sprintf('https://api.github.com/repos/%s/releases?per_page=100', $repository));
    if (! is_array($payload)) {
        return null;
    }

    $tags = [];
    foreach ($payload as $release) {
        if (is_array($release) && is_string($release['tag_name'] ?? null)) {
            $tags[] = $release['tag_name'];
        }
    }

    return $tags;
}

function githubDefaultBranchSha(string $repository): ?string
{
    $payload = githubApi(sprintf('https://api.github.com/repos/%s/commits?per_page=1', $repository));

    if (! is_array($payload) || ! isset($payload[0]) || ! is_array($payload[0])) {
        return null;
    }

    $sha = $payload[0]['sha'] ?? null;

    return is_string($sha) ? $sha : null;
}

/**
 * @return array<mixed>|null
 */
function githubApi(string $url): ?array
{
    $headers = ['User-Agent: ucp-php-sdk-spec-drift', 'Accept: application/vnd.github+json'];

    // Authenticated when a token is present, which in CI raises the rate limit from 60 requests
    // an hour to 5000. Unauthenticated still works, so this runs locally without setup.
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

function run(string $command): bool
{
    exec($command . ' 2>/dev/null', $output, $status);

    return $status === 0;
}
