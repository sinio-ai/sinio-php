<?php

declare(strict_types=1);

namespace Sinio\Model;

/**
 * The four structural roles the derivation engine resolves, each a raw
 * identifier from the source. Anything else (ticket number, firmware, ...)
 * belongs in the event payload; the API refuses any other role.
 */
final class EventRoles
{
    public function __construct(
        public readonly ?string $device = null,
        public readonly ?string $subject = null,
        public readonly ?string $location = null,
        public readonly ?string $actor = null,
    ) {
    }
}
