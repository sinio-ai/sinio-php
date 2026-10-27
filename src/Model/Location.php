<?php

declare(strict_types=1);

namespace Sinio\Model;

final class Location
{
    /** @param string|null $parent external id of the parent location, if any */
    public function __construct(
        public readonly string $externalId,
        public readonly LocationLevel|string $kind,
        public readonly string $name,
        public readonly ?string $parent = null,
    ) {
    }
}
