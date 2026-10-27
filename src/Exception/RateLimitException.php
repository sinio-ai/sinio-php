<?php

declare(strict_types=1);

namespace Sinio\Exception;

/** 429 -- quota exceeded. `retryAfterSeconds` mirrors the `Retry-After` header. */
final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        int $status,
        mixed $body = null,
        public readonly ?float $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $status, $body);
    }
}
