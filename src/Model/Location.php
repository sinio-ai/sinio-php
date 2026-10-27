<?php

declare(strict_types=1);

namespace Sinio\Model;

final class Location
{
    /**
     * @param LocationLevel|string $kind a string must be one of the `LocationLevel` values
     * @param string|null $parent external id of the parent location, if any
     */
    public function __construct(
        public readonly string $externalId,
        public readonly LocationLevel|string $kind,
        public readonly string $name,
        public readonly ?string $parent = null,
    ) {
    }
}
