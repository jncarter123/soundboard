<?php

namespace App\Alerts;

/**
 * A webhook to send an alert to, with the secret it's signed with. A team's
 * webhook uses the team's own secret and, unless private addresses are
 * allowed, may only reach public https endpoints.
 */
final readonly class WebhookTarget
{
    public function __construct(
        public string $url,
        public string $secret,
        public bool $publicOnly = false,
    ) {}
}
