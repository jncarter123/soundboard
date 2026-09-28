<?php

namespace App\Support;

/**
 * DNS lookups, behind a class so tests can answer them.
 */
class HostResolver
{
    /**
     * @return list<string> Every IPv4 and IPv6 address the host resolves to.
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
