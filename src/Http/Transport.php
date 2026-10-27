<?php

declare(strict_types=1);

namespace Sinio\Http;

use Sinio\Exception\ApiException;
use Sinio\Exception\AuthException;
use Sinio\Exception\ConnectionException;
use Sinio\Exception\RateLimitException;
use Sinio\Exception\RequestException;
use Sinio\Exception\ServerException;
use Sinio\Internal\Json;

/** Retries, backoff, and the mapping of statuses to exceptions. */
final class Transport
{
    private readonly string $baseUrl;

    /** @param (callable(float): void)|null $sleep injected in tests so retries do not actually wait */
    public function __construct(
        string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly float $retryBaseDelay,
        private readonly string $userAgent,
        private readonly Sender $sender = new CurlSender(),
        private readonly mixed $sleep = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, array $headers): array
    {
        $url = $this->baseUrl . $path;
        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $requestHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
            ...$headers,
        ];
        $attempt = 0;

        while (true) {
            try {
                $response = $this->sender->send($url, $payload, $requestHeaders, $this->timeout);
            } catch (ConnectionException $error) {
                if ($attempt >= $this->maxRetries) {
                    throw $error;
                }
                $this->wait($this->backoff($attempt, null));
                ++$attempt;
                continue;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return Json::object(self::decode($response->body));
            }

            $retryAfter = self::parseRetryAfter($response->headers['retry-after'] ?? null);
            if (self::isRetriable($response->status) && $attempt < $this->maxRetries) {
                $this->wait($this->backoff($attempt, $retryAfter));
                ++$attempt;
                continue;
            }

            throw self::toApiException($response, $retryAfter);
        }
    }

    /** `Retry-After` is either a delay in seconds or an HTTP date. Both are accepted. */
    public static function parseRetryAfter(?string $header, ?int $now = null): ?float
    {
        if ($header === null) {
            return null;
        }
        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }
        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (float) ($timestamp - ($now ?? time())));
    }

    /** A 429 or a 5xx is retried; ingestion is idempotent so a replay is only ever a duplicate. */
    private static function isRetriable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /** Exponential backoff with full jitter, unless the server said how long to wait. */
    private function backoff(int $attempt, ?float $retryAfter): float
    {
        if ($retryAfter !== null) {
            return $retryAfter;
        }
        $ceiling = min($this->retryBaseDelay * (2 ** $attempt), 30.0);

        return (mt_rand() / mt_getrandmax()) * $ceiling;
    }

    private function wait(float $seconds): void
    {
        if (is_callable($this->sleep)) {
            ($this->sleep)($seconds);

            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }

    private static function decode(string $body): mixed
    {
        if ($body === '') {
            return null;
        }
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    private static function toApiException(HttpResponse $response, ?float $retryAfter): ApiException
    {
        $body = self::decode($response->body);
        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : null;
        $message = $response->status . ': ' . (self::extractMessage($body) ?? 'no message');

        return match (true) {
            $response->status === 401, $response->status === 403 => new AuthException(
                $message,
                $response->status,
                $body,
                $code,
            ),
            $response->status === 429 => new RateLimitException($message, $response->status, $body, $retryAfter),
            $response->status >= 500 => new ServerException($message, $response->status, $body, $code),
            default => new RequestException($message, $response->status, $body, $code),
        };
    }

    private static function extractMessage(mixed $body): ?string
    {
        if (is_string($body)) {
            return $body;
        }
        if (!is_array($body)) {
            return null;
        }
        $message = $body['message'] ?? null;
        // NestJS's ValidationPipe returns an array of messages, one per failed rule.
        if (is_array($message)) {
            return implode('; ', array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item, JSON_THROW_ON_ERROR),
                $message,
            ));
        }

        return is_string($message) ? $message : null;
    }
}
