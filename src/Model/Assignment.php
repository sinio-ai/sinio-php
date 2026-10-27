<?php

declare(strict_types=1);

namespace Sinio\Model;

final class Assignment
{
    /**
     * @param string $credential external id of the credential (badge, card, ...)
     * @param string $subject external id of the subject the credential is assigned to
     */
    public function __construct(
        public readonly string $credential,
        public readonly string $subject,
        public readonly string|\DateTimeInterface $validFrom,
        public readonly string|\DateTimeInterface|null $validTo = null,
        public readonly ?string $relation = null,
    ) {
    }
}
