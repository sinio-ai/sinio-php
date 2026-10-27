<?php

declare(strict_types=1);

namespace Sinio\Exception;

/** A 4xx or 5xx response. */
class ApiException extends SinioException
{
    /**
     * @param mixed $body decoded response body, when it was JSON
     * @param string|null $errorCode rejection code returned by the API, when the body carries one.
     *                               Named `errorCode` because `\Exception::$code` is already taken.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly mixed $body = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
