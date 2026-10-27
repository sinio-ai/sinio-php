<?php

declare(strict_types=1);

namespace Sinio\Exception;

/** Raised before any network call, when the input cannot be a valid request. */
final class ValidationException extends SinioException
{
}
