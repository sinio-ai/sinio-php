<?php

declare(strict_types=1);

namespace Sinio\Tests;

use Sinio\Http\HttpResponse;
use Sinio\Http\Sender;

/** Records the calls and replays a scripted list of responses. */
final class StubSender implements Sender
{
    /** @var list<array{url: string, headers: array<string, string>, body: array<string, mixed>, raw: string}> */
    public array $calls = [];

    private int $index = 0;

    /** @param list<array{0: int, 1: array<string, mixed>, 2?: array<string, string>}> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function send(string $url, string $payload, array $headers, float $timeout): HttpResponse
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $decoded, 'raw' => $payload];

        $response = $this->responses[min($this->index, count($this->responses) - 1)];
        ++$this->index;

        return new HttpResponse(
            $response[0],
            $response[2] ?? [],
            json_encode($response[1], JSON_THROW_ON_ERROR),
        );
    }
}
