<?php

declare(strict_types=1);

namespace Sinio\Internal;

/**
 * Reading of decoded JSON responses, which are never trusted to have the expected shape: an unexpected value
 * reads as empty rather than failing, so a response the API extends later never breaks the client.
 *
 * @internal
 */
final class Json
{
    /**
     * The value as a JSON object: its named members, or an empty map when it is not an object.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $object = [];
        foreach ($value as $key => $member) {
            if (is_string($key)) {
                $object[$key] = $member;
            }
        }

        return $object;
    }

    /**
     * The objects listed under `$key`, skipping any item that is not one.
     *
     * @param array<string, mixed> $object
     *
     * @return list<array<string, mixed>>
     */
    public static function objects(array $object, string $key): array
    {
        $value = $object[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $rows[] = self::object($item);
            }
        }

        return $rows;
    }
}
