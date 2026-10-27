<?php

declare(strict_types=1);

namespace Sinio\Exception;

/** 5xx -- temporary. Safe to replay verbatim: ingestion is idempotent. */
final class ServerException extends ApiException
{
}
