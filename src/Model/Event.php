<?php

declare(strict_types=1);

namespace Sinio\Model;

final class Event
{
    /**
     * @param string $sourceEventId identity at the source, stable over time; part of the idempotency key
     * @param string $type `code` of an event type in the catalog
     * @param string|\DateTimeInterface $occurredAt ISO 8601 with an offset; without one the API rejects the event
     * @param array<string, mixed>|null $payload a JSON object (an associative array, never a list), free-form and optional (`{}` server-side when null); readings (battery, signal, ...) are read from it as its source declares them
     * @param EventRoles|null $roles the four roles, read from here only, never from the payload
     * @param string|null $sourceVersion source-side version, kept for tracing: it plays no part in deduplication
     * @param string|null $project project `external_id`; only for organization-wide keys (issued on request), which cover several projects; forbidden for project keys
     * @param int|null $count how many units this one event stands for, from 1 to 1,000,000; null = 1
     */
    public function __construct(
        public readonly string $sourceEventId,
        public readonly string $type,
        public readonly string|\DateTimeInterface $occurredAt,
        public readonly ?array $payload = null,
        public readonly ?EventRoles $roles = null,
        public readonly ?string $sourceVersion = null,
        public readonly ?string $project = null,
        public readonly ?string $label = null,
        public readonly ?int $count = null,
    ) {
    }

    public function withProject(string $project): self
    {
        return new self(
            $this->sourceEventId,
            $this->type,
            $this->occurredAt,
            $this->payload,
            $this->roles,
            $this->sourceVersion,
            $project,
            $this->label,
            $this->count,
        );
    }
}
