<?php

declare(strict_types=1);

namespace Sinio\Http;

use Sinio\Exception\ConnectionException;

/**
 * Transport seam. Implement it to route calls through Guzzle, Symfony
 * HttpClient or any PSR-18 client already present in the project -- or to stub
 * the network in tests.
 */
interface Sender
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionException when no HTTP response could be obtained at all
     */
    public function send(string $url, string $payload, array $headers, float $timeout): HttpResponse;
}
