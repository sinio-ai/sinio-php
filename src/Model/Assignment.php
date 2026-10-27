<?php

declare(strict_types=1);

namespace Sinio\Model;

final class Assignment
{
    /**
     * @param string $credential external id of the credential (badge, card, ...)
     * @param string $subject external id of the subject the credential is assigned to
     * @param string|\DateTimeInterface $validFrom ISO 8601 with an offset (`Z` or `+02:00`)
     * @param string|\DateTimeInterface|null $validTo same form, strictly after `$validFrom`; null = open-ended
     * @param AssignmentRelation|string|null $relation a string must be one of the `AssignmentRelation` values;
     *                                                 null leaves the API default (`assigned_to`)
     */
    public function __construct(
        public readonly string $credential,
        public readonly string $subject,
        public readonly string|\DateTimeInterface $validFrom,
        public readonly string|\DateTimeInterface|null $validTo = null,
        public readonly AssignmentRelation|string|null $relation = null,
    ) {
    }
}
