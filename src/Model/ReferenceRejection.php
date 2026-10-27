<?php

declare(strict_types=1);

namespace Sinio\Model;

final class ReferenceRejection
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
