<?php

declare(strict_types=1);

namespace Sinio\Model;

/**
 * Outcome of `SinioClient::sendEvents()`. `duplicates` and `revisions` are
 * successes, not errors: replaying a batch is expected and never stores
 * anything twice.
 */
final class SendEventsResult
{
    /**
     * @param list<string> $batchIds one server-side batch id per HTTP call (batches are chunked at 500)
     * @param list<EventRejection> $rejections
     * @param bool $testMode the key is in test mode: its events are checked and kept apart, never used for detection
     */
    public function __construct(
        public readonly array $batchIds = [],
        public readonly int $accepted = 0,
        public readonly int $pendingClassification = 0,
        public readonly int $duplicates = 0,
        public readonly int $revisions = 0,
        public readonly int $rejected = 0,
        public readonly array $rejections = [],
        public readonly bool $testMode = false,
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->batchIds, ...$other->batchIds],
            $this->accepted + $other->accepted,
            $this->pendingClassification + $other->pendingClassification,
            $this->duplicates + $other->duplicates,
            $this->revisions + $other->revisions,
            $this->rejected + $other->rejected,
            [...$this->rejections, ...$other->rejections],
            $this->testMode || $other->testMode,
        );
    }
}
