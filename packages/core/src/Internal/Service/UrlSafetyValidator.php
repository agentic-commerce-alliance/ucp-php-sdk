<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Exception\ValidationException;

/** @internal */
final class UrlSafetyValidator
{
    /** @var list<int> */
    private const REMOTE_ALLOWED_PORTS = [443, 8443];

    /** @var list<string> */
    private const BLOCKED_HOSTS = [
        '169.254.169.254',
        'metadata.google.internal',
        'metadata',
    ];

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(
        private readonly array $allowedHosts = [],
        private readonly ?\Closure $dnsResolver = null,
        private readonly bool $profileFetchingDevelopmentMode = false,
    ) {
    }

    public function assertAllowed(string $uri): void
    {
        $this->validateAndResolve($uri);
    }

    /**
     * @param list<string>|null $allowedHostsOverride when non-null, replaces the
     *        constructor allowlist for this call (e.g. the resolved sales channel's
     *        allowed profile hosts) so the SSRF gate is scoped per request
     */
    public function validateAndResolve(string $uri, ?array $allowedHostsOverride = null): ValidatedProfileUri
    {
        $allowedHosts = $allowedHostsOverride ?? $this->allowedHosts;

        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new ValidationException('Profile URI must use http or https.');
        }

        if ($host === '') {
            throw new ValidationException('Profile URI must contain a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException('Profile URI must not include userinfo.');
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new ValidationException(sprintf('Profile host "%s" is blocked.', $host));
        }

        if ($scheme === 'http') {
            if (! $this->isLocalHost($host)) {
                throw new ValidationException('Plain http is only allowed for local development hosts.');
            }

            if (! $this->profileFetchingDevelopmentMode) {
                throw new ValidationException('Plain http is only allowed when profile fetching development mode is enabled.');
            }
        }

        if (! $this->isLocalHost($host) && ! in_array($port, self::REMOTE_ALLOWED_PORTS, true)) {
            throw new ValidationException(sprintf('Profile port "%d" is not allowed.', $port));
        }

        $resolution = $this->assertHostDoesNotResolveToBlockedAddress($host);

        if ($allowedHosts === [] && $this->profileFetchingDevelopmentMode && $this->isLocalHost($host)) {
            return new ValidatedProfileUri($uri, $host, $port, $resolution['resolved_ip'], $resolution['uses_dns']);
        }

        if ($allowedHosts === []) {
            throw new ValidationException(sprintf('Profile host "%s" is not allowed.', $host));
        }

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower($allowedHost);

            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return new ValidatedProfileUri($uri, $host, $port, $resolution['resolved_ip'], $resolution['uses_dns']);
            }
        }

        throw new ValidationException(sprintf('Profile host "%s" is not allowed.', $host));
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @return array{resolved_ip: string|null, uses_dns: bool}
     */
    private function assertHostDoesNotResolveToBlockedAddress(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isLocalHost($host) && $this->isBlockedIp($host)) {
                throw new ValidationException(sprintf('Profile host "%s" resolves to a blocked IP address.', $host));
            }

            return [
                'resolved_ip' => null,
                'uses_dns' => false,
            ];
        }

        $addresses = $this->resolveHost($host);
        if ($addresses === []) {
            throw new ValidationException(sprintf('Profile host "%s" did not resolve to any IP address.', $host));
        }

        $allowLocalResolution = $this->isLocalHost($host);
        foreach ($addresses as $address) {
            if ($allowLocalResolution && $this->isLocalHost($address)) {
                continue;
            }

            if ($this->isBlockedIp($address)) {
                throw new ValidationException(sprintf('Profile host "%s" resolves to a blocked IP address.', $host));
            }
        }

        return [
            'resolved_ip' => $addresses[0],
            'uses_dns' => true,
        ];
    }

    private function isBlockedIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if ($this->dnsResolver !== null) {
            $addresses = ($this->dnsResolver)($host);

            return array_values(array_unique(array_values(array_filter(
                is_array($addresses) ? $addresses : [],
                static fn (mixed $address): bool => is_string($address) && $address !== '',
            ))));
        }

        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            $addresses = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
