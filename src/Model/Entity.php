<?php

declare(strict_types=1);

namespace Sinio\Model;

final class Entity
{
    /**
     * @param string|null $location external id of the location the entity sits in
     * @param array<string, mixed>|null $attributes free-form attributes stored alongside the entity
     */
    public function __construct(
        public readonly string $externalId,
        public readonly EntityKind|string $kind,
        public readonly ?string $subkind = null,
        public readonly ?string $label = null,
        public readonly ?string $location = null,
        public readonly ?array $attributes = null,
    ) {
    }
}
