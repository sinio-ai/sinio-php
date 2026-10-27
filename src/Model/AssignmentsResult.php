<?php

declare(strict_types=1);

namespace Sinio\Model;

final class AssignmentsResult
{
    /** @param list<AssignmentRejection> $rejections */
    public function __construct(
        public readonly int $accepted = 0,
        public readonly int $rejected = 0,
        public readonly array $rejections = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            $this->accepted + $other->accepted,
            $this->rejected + $other->rejected,
            [...$this->rejections, ...$other->rejections],
        );
    }
}
