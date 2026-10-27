<?php

declare(strict_types=1);

namespace Sinio\Model;

final class ReferenceRejection
{
    /** The code as a known `RejectionCode`, or null for a code added to the API after this SDK version. */
    public readonly ?RejectionCode $knownCode;

    /** @param string $code raw code, kept as sent even when this SDK version does not know it */
    public function __construct(
        public readonly string $externalId,
        public readonly string $code,
        public readonly string $message,
    ) {
        $this->knownCode = RejectionCode::tryFrom($code);
    }
}
