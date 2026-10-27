<?php

declare(strict_types=1);

namespace Sinio\Model;

final class EventRejection
{
    public function __construct(
        public readonly string $sourceEventId,
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
