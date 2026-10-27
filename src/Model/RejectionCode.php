<?php

declare(strict_types=1);

namespace Sinio\Model;

/**
 * The rejection codes the API documents today, on `rejections[].code` of every response.
 *
 * The list is additive: the API may add a code at any time. Rejections therefore keep the raw `string $code`
 * and expose `?RejectionCode $knownCode`, null for a code this SDK version does not know yet.
 */
enum RejectionCode: string
{
    case PayloadTooLarge = 'payload_too_large';
    case UnknownProject = 'unknown_project';
    case UnknownSource = 'unknown_source';
    case ArchivedSource = 'archived_source';
    case SourceMismatch = 'source_mismatch';
    case AmbiguousSource = 'ambiguous_source';
    case InvalidOccurredAt = 'invalid_occurred_at';
    case MissingRequiredField = 'missing_required_field';
    case UnknownLocation = 'unknown_location';
    case UnknownParentLocation = 'unknown_parent_location';
    case OverlappingAssignment = 'overlapping_assignment';
    case InvalidValidityPeriod = 'invalid_validity_period';
}
