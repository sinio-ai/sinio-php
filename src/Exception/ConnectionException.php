<?php

declare(strict_types=1);

namespace Sinio\Exception;

/** The request never got a usable HTTP response (DNS, TCP, TLS, timeout). */
final class ConnectionException extends SinioException
{
}
