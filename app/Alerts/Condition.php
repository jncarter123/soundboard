<?php

namespace App\Alerts;

/**
 * Something that is wrong right now. Identified by its key, so the same
 * problem found on consecutive checks maps to the same alert.
 */
final readonly class Condition
{
    public function __construct(
        public string $key,
        public string $type,
        public string $severity,
        public string $message,
        public ?string $appId = null,
        public array $details = [],
    ) {}
}
