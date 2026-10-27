<?php

declare(strict_types=1);

namespace Sinio\Model;

final class AssignmentRejection
{
    public function __construct(
        public readonly string $credential,
        public readonly string $subject,
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
