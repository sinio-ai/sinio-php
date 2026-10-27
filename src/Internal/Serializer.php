<?php

declare(strict_types=1);

namespace Sinio\Internal;

use Sinio\Exception\ValidationException;
use Sinio\Model\Assignment;
use Sinio\Model\Entity;
use Sinio\Model\Event;
use Sinio\Model\Location;

/**
 * Mapping between the SDK models and the JSON wire format.
 *
 * @internal
 */
final class Serializer
{
    /** The API caps every collection at 500 items per call. */
    public const MAX_ITEMS_PER_CALL = 500;

    /** The most units one event may stand for. */
    public const MAX_EVENT_COUNT = 1_000_000;

    // The API's own rule: a `T`, seconds, an optional fraction, then `Z` or an offset. It rejects the
    // event otherwise (`invalid_occurred_at`); refused here, it is a fixable local error before any call.
    private const ISO_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

    public static function toIso(string|\DateTimeInterface $value, string $field): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339);
        }
        if (preg_match(self::ISO_WITH_OFFSET, $value) !== 1) {
            throw new ValidationException(
                "{$field} must be an ISO 8601 timestamp with an offset (got '{$value}')"
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed> the same record without its null values, so they never reach the wire
     */
    public static function compact(array $record): array
    {
        return array_filter($record, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     * @param int<1, max> $size
     *
     * @return list<list<T>>
     */
    public static function chunked(array $items, int $size): array
    {
        return array_chunk($items, $size);
    }

    /** @return array<string, mixed> */
    public static function event(Event $event, int $index): array
    {
        if ($event->sourceEventId === '') {
            throw new ValidationException("events[{$index}].sourceEventId is required");
        }
        if ($event->type === '') {
            throw new ValidationException("events[{$index}].type is required");
        }
        if ($event->count !== null && ($event->count < 1 || $event->count > self::MAX_EVENT_COUNT)) {
            throw new ValidationException(
                "events[{$index}].count must be an integer between 1 and " . self::MAX_EVENT_COUNT
            );
        }

        $roles = $event->roles;

        return self::compact([
            'source_event_id' => $event->sourceEventId,
            'source_version' => $event->sourceVersion,
            'project' => $event->project,
            'type' => $event->type,
            'label' => $event->label,
            'occurred_at' => self::toIso($event->occurredAt, "events[{$index}].occurredAt"),
            'roles' => $roles === null ? null : self::compact([
                'device' => $roles->device,
                'subject' => $roles->subject,
                'location' => $roles->location,
                'actor' => $roles->actor,
            ]),
            'payload' => $event->payload === null ? null : (object) $event->payload,
            'count' => $event->count,
        ]);
    }

    /** @return array<string, mixed> */
    public static function entity(Entity $entity, int $index): array
    {
        if ($entity->externalId === '') {
            throw new ValidationException("entities[{$index}].externalId is required");
        }

        return self::compact([
            'external_id' => $entity->externalId,
            'kind' => $entity->kind instanceof \BackedEnum ? $entity->kind->value : $entity->kind,
            'subkind' => $entity->subkind,
            'label' => $entity->label,
            'location' => $entity->location,
            'attributes' => $entity->attributes === null ? null : (object) $entity->attributes,
        ]);
    }

    /** @return array<string, mixed> */
    public static function location(Location $location, int $index): array
    {
        if ($location->externalId === '') {
            throw new ValidationException("locations[{$index}].externalId is required");
        }
        if ($location->name === '') {
            throw new ValidationException("locations[{$index}].name is required");
        }

        return self::compact([
            'external_id' => $location->externalId,
            'kind' => $location->kind instanceof \BackedEnum ? $location->kind->value : $location->kind,
            'name' => $location->name,
            'parent' => $location->parent,
        ]);
    }

    /** @return array<string, mixed> */
    public static function assignment(Assignment $assignment, int $index): array
    {
        if ($assignment->credential === '' || $assignment->subject === '') {
            throw new ValidationException("assignments[{$index}] requires both credential and subject");
        }

        return self::compact([
            'credential' => $assignment->credential,
            'subject' => $assignment->subject,
            'relation' => $assignment->relation,
            'valid_from' => self::toIso($assignment->validFrom, "assignments[{$index}].validFrom"),
            'valid_to' => $assignment->validTo === null
                ? null
                : self::toIso($assignment->validTo, "assignments[{$index}].validTo"),
        ]);
    }
}
