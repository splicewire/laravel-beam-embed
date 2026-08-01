<?php

namespace Splicewire\Beam\Embed\Capability;

/**
 * The outcome of one brokered-capability call (RCP-05): either an allowed result the
 * host executed server-side, or a refusal with a machine-readable reason. Never carries
 * a credential — only the resolved data or the refusal.
 */
class BrokerResult
{
    /**
     * @param  mixed  $data  the server-side result when allowed
     * @param  string|null  $reason  the refusal reason when refused
     */
    private function __construct(
        public bool $allowed,
        public mixed $data = null,
        public ?string $reason = null,
        public int $status = 200,
    ) {}

    public static function allow(mixed $data): self
    {
        return new self(allowed: true, data: $data);
    }

    public static function refuse(string $reason, int $status = 403): self
    {
        return new self(allowed: false, reason: $reason, status: $status);
    }
}
