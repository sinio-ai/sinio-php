<?php

declare(strict_types=1);

namespace Sinio\Exception;

/** 401 / 403 -- invalid, revoked, or insufficiently scoped key. Never replay. */
final class AuthException extends ApiException
{
}
