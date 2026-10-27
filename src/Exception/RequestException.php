<?php

declare(strict_types=1);

namespace Sinio\Exception;

/** 400 / 404 / 413 -- the request itself is wrong. Fix it before replaying. */
final class RequestException extends ApiException
{
}
