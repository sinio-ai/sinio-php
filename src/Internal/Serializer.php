<?php

declare(strict_types=1);

namespace Sinio\Internal;

use Sinio\Exception\ValidationException;
use Sinio\Model\Assignment;
use Sinio\Model\AssignmentRelation;
use Sinio\Model\Entity;
use Sinio\Model\EntityKind;
use Sinio\Model\Event;
use Sinio\Model\EventRoles;
use Sinio\Model\Location;
use Sinio\Model\LocationLevel;

/**
 * Mapping between the SDK models and the JSON wire format.
 *
 * Everything the API would refuse for a wrong value is refused here first, with a `ValidationException` naming
 * the field and its index, before any network call.
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
        self::requireString($value, $field);
        if (preg_match(self::ISO_WITH_OFFSET, $value) !== 1) {
            throw new ValidationException(
                "{$field} must be an ISO 8601 timestamp with an offset (got \"{$value}\")"
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
        $at = "events[{$index}]";
        self::requireString($event->sourceEventId, "{$at}.sourceEventId");
        self::requireString($event->type, "{$at}.type");
        $occurredAt = self::toIso($event->occurredAt, "{$at}.occurredAt");
        if ($event->count !== null && ($event->count < 1 || $event->count > self::MAX_EVENT_COUNT)) {
            throw new ValidationException(
                "{$at}.count must be an integer between 1 and " . self::MAX_EVENT_COUNT . " (got {$event->count})"
            );
        }

        return self::compact([
            'source_event_id' => $event->sourceEventId,
            'source_version' => $event->sourceVersion,
            'project' => $event->project,
            'type' => $event->type,
            'label' => $event->label,
            'occurred_at' => $occurredAt,
            'roles' => $event->roles === null ? null : self::roles($event->roles, "{$at}.roles"),
            'payload' => self::jsonObject($event->payload, "{$at}.payload"),
            'count' => $event->count,
        ]);
    }

    /** @return array<string, mixed> */
    public static function entity(Entity $entity, int $index): array
    {
        $at = "entities[{$index}]";
        self::requireString($entity->externalId, "{$at}.externalId");
        $kind = self::vocabulary($entity->kind, EntityKind::class, "{$at}.kind");
        if ($entity->subkind === '') {
            throw new ValidationException("{$at}.subkind must not be empty when given (omit it instead)");
        }

        return self::compact([
            'external_id' => $entity->externalId,
            'kind' => $kind->value,
            'subkind' => $entity->subkind,
            'label' => $entity->label,
            'location' => $entity->location,
            'attributes' => self::jsonObject($entity->attributes, "{$at}.attributes"),
        ]);
    }

    /** @return array<string, mixed> */
    public static function location(Location $location, int $index): array
    {
        $at = "locations[{$index}]";
        self::requireString($location->externalId, "{$at}.externalId");
        $kind = self::vocabulary($location->kind, LocationLevel::class, "{$at}.kind");
        self::requireString($location->name, "{$at}.name");

        return self::compact([
            'external_id' => $location->externalId,
            'kind' => $kind->value,
            'name' => $location->name,
            'parent' => $location->parent,
        ]);
    }

    /** @return array<string, mixed> */
    public static function assignment(Assignment $assignment, int $index): array
    {
        $at = "assignments[{$index}]";
        self::requireString($assignment->credential, "{$at}.credential");
        self::requireString($assignment->subject, "{$at}.subject");
        $relation = $assignment->relation === null
            ? null
            : self::vocabulary($assignment->relation, AssignmentRelation::class, "{$at}.relation");
        $validFrom = self::toIso($assignment->validFrom, "{$at}.validFrom");
        $validTo = $assignment->validTo === null ? null : self::toIso($assignment->validTo, "{$at}.validTo");
        // The API's `invalid_validity_period`: the range must not be empty nor reversed.
        if ($validTo !== null && self::epochMillis($validTo) <= self::epochMillis($validFrom)) {
            throw new ValidationException(
                "{$at}.validTo must be after validFrom (got \"{$validTo}\" for \"{$validFrom}\")"
            );
        }

        return self::compact([
            'credential' => $assignment->credential,
            'subject' => $assignment->subject,
            'relation' => $relation?->value,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);
    }

    /** @return array<string, string> */
    private static function roles(EventRoles $roles, string $field): array
    {
        $given = [
            'device' => $roles->device,
            'subject' => $roles->subject,
            'location' => $roles->location,
            'actor' => $roles->actor,
        ];
        $wire = [];
        foreach ($given as $role => $value) {
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                throw new ValidationException("{$field}.{$role} must not be empty when given (omit it instead)");
            }
            $wire[$role] = $value;
        }

        return $wire;
    }

    /**
     * A JSON object on the wire: an associative array, never a list. An empty array stays `{}`, since PHP
     * cannot tell an empty map from an empty list.
     *
     * @param array<mixed>|null $value
     */
    private static function jsonObject(?array $value, string $field): ?object
    {
        if ($value === null) {
            return null;
        }
        if ($value !== [] && array_is_list($value)) {
            throw new ValidationException("{$field} must be a JSON object (an associative array), not a list");
        }

        return (object) $value;
    }

    /**
     * Resolves a vocabulary value given as its enum case or as its raw string.
     *
     * @template E of \BackedEnum
     *
     * @param E|string $value
     * @param class-string<E> $enum
     *
     * @return E
     */
    private static function vocabulary(\BackedEnum|string $value, string $enum, string $field): \BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }
        if (!is_string($value)) {
            throw new ValidationException("{$field} must be a " . $enum . ' case');
        }
        self::requireString($value, $field);
        $case = $enum::tryFrom($value);
        if ($case === null) {
            $allowed = implode(
                ', ',
                array_map(static fn (\BackedEnum $known): string => (string) $known->value, $enum::cases()),
            );
            throw new ValidationException("{$field} must be one of {$allowed} (got \"{$value}\")");
        }

        return $case;
    }

    private static function requireString(string $value, string $field): void
    {
        if ($value === '') {
            throw new ValidationException("{$field} is required");
        }
    }

    /** Milliseconds since the epoch, the precision the API compares validity bounds at. */
    private static function epochMillis(string $iso): int
    {
        $moment = new \DateTimeImmutable($iso);

        return $moment->getTimestamp() * 1000 + (int) $moment->format('v');
    }
}
