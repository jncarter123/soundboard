<?php

namespace App\Support;

use RuntimeException;

/**
 * Keeps requests to user-supplied URLs (team webhooks) out of the private
 * network: https only, and every address the host resolves to must be
 * public. The address is checked again at send time and pinned, so a DNS
 * change between the check and the request can't redirect it.
 */
class PublicUrlGuard
{
    public function __construct(
        protected HostResolver $resolver,
    ) {}

    /**
     * Why the URL isn't allowed, or null if it is.
     */
    public function problem(string $url): ?string
    {
        try {
            $this->pin($url);

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * The public address to connect to, as a curl resolve entry
     * ("host:port:address").
     *
     * @throws RuntimeException if the URL isn't allowed
     */
    public function pin(string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (($parts['scheme'] ?? null) !== 'https' || blank($host)) {
            throw new RuntimeException('The webhook URL must start with https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('The webhook URL must not contain a username or password.');
        }

        // An IP address as the host is checked as is, never looked up.
        $literal = trim($host, '[]');
        $addresses = filter_var($literal, FILTER_VALIDATE_IP) ? [$literal] : $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new RuntimeException("The webhook host {$host} could not be resolved.");
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException("The webhook host {$host} resolves to a private or reserved address.");
            }
        }

        $address = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];

        return trim($host, '[]').':'.($parts['port'] ?? 443).':'.$address;
    }
}
