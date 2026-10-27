<?php

declare(strict_types=1);

namespace Sinio\Http;

use Sinio\Exception\ConnectionException;

/** Default transport: ext-curl, no framework dependency. */
final class CurlSender implements Sender
{
    public function send(string $url, string $payload, array $headers, float $timeout): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ConnectionException("Could not initialise a cURL handle for {$url}");
        }

        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => "{$name}: {$value}",
                array_keys($headers),
                array_values($headers),
            ),
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new ConnectionException("Request to {$url} failed: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse($status, $responseHeaders, is_string($body) ? $body : '');
    }
}
