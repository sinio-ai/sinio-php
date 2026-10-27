<?php

declare(strict_types=1);

namespace Sinio\Http;

final class HttpResponse
{
    /** @param array<string, string> $headers lower-cased header names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
