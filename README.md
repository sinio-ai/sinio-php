# sinio/sinio-php

Official client for the sinio.ai ingestion API. PHP 8.1+, `ext-curl` and
`ext-json` only — no framework, no HTTP client dependency. PHPStan max level clean.

```bash
composer require sinio/sinio-php
```

## Quick start

```php
use Sinio\SinioClient;
use Sinio\Model\Event;
use Sinio\Model\EventRoles;

$sinio = new SinioClient(
    apiKey: getenv('SINIO_API_KEY'),
    baseUrl: 'https://api.sinio.ai',
);

$result = $sinio->sendEvents([
    new Event(
        sourceEventId: 'evt_88213',
        type: 'badge_in',
        occurredAt: '2026-08-28T07:58:12+02:00',
        payload: ['badge' => '04A2B9C1', 'reader_firmware' => '2.4.1'],
        roles: new EventRoles(device: 'BORNE-A12', subject: 'P-4582', location: 'SITE-A.HALL'),
    ),
]);

printf("%d accepted, %d duplicates, %d rejected\n",
    $result->accepted, $result->duplicates, $result->rejected);
```

The four roles (device, subject, location, actor) are read from `roles` only;
the API refuses any other key. `payload` is free and optional (`{}` by default).
`occurredAt` also accepts a `DateTimeInterface`.

## What the client does for you

- **Chunking.** Pass 50 000 events; the SDK splits them into calls of 500 (the API
  limit) and merges the counters and rejections into one result.
- **Retries.** 429, 5xx and dropped connections are retried with exponential
  backoff and full jitter, honouring `Retry-After`. This is safe because
  a resend with the same `source_event_id` and the same content is counted in
  `duplicates`, never stored twice.
- **Local validation.** What the API would refuse is refused before any call, with a
  `ValidationException` naming the field and its index (`entities[3].kind must be one of
  device, subject, asset, actor, credential (got "devise")`): a value outside its vocabulary,
  a missing required field, an empty `subkind` or role, a `payload` or `attributes` that is a
  list rather than an associative array, a timestamp without a UTC offset (the API's own rule),
  a `validTo` not after `validFrom`.
- **Typed exceptions.** `AuthException` (401/403, do not replay),
  `RequestException` (400/404/413, fix the request), `RateLimitException`
  (429, carries `retryAfterSeconds`), `ServerException` (5xx, replayable),
  `ConnectionException`, `ValidationException`. All extend `SinioException`.
  The API rejection code is on `$exception->errorCode` (`$code` belongs to
  `\Exception`).

## Partial acceptance

A 200 means the batch was **received**, not derived — derivation is asynchronous.
Within a batch, valid events are accepted even when others are rejected, so always
read `rejections`:

```php
use Sinio\Model\RejectionCode;

foreach ($result->rejections as $rejection) {
    $logger->warning("{$rejection->sourceEventId} {$rejection->code}: {$rejection->message}");
    if ($rejection->knownCode === RejectionCode::UnknownSource) {
        // ...
    }
}
```

`code` is the raw string; `knownCode` is the matching `RejectionCode` case, or `null` for a
code added to the API after your SDK version (the list only grows, never breaks).

`duplicates` and `revisions` are successes, not failures.

## Reference data

```php
use Sinio\Model\{Assignment, Entity, EntityKind, Location, LocationLevel};

$sinio->upsertLocations([
    new Location(externalId: 'SITE-A', kind: LocationLevel::Site, name: 'Site A'),
    new Location(externalId: 'SITE-A.HALL', kind: LocationLevel::Zone, name: 'Hall', parent: 'SITE-A'),
]);

$sinio->upsertEntities([
    new Entity(
        externalId: 'BORNE-A12',
        kind: EntityKind::Device,
        subkind: 'nfc_kiosk',
        location: 'SITE-A.HALL',
        attributes: ['firmware' => '2.4.1'],
    ),
]);

$sinio->createAssignments([
    new Assignment(
        credential: '04A2B9C1',
        subject: 'P-4582',
        validFrom: '2026-01-01T00:00:00+01:00',
        relation: 'assigned_to',
    ),
]);
```

Vocabularies are backed enums: `EntityKind`, `LocationLevel`, `AssignmentRelation`,
`IngestionMode`, `RejectionCode`. Their raw strings (`kind: 'device'`) are accepted too, and
checked against the enum.

The reference is never a condition of acceptance: an event pointing at an unknown
device is still accepted. Upserting is how you enrich it, not how you gate it.

## Backfill

```php
$sinio->backfillEvents($history);
```

`backfill` persists history without raising alerts; once it has arrived, the project's
processes replay it in a canonical order. Live catch-up after an outage should stay on `sendEvents()`
— the declared mode decides alerting, not the age of the events.

## Webhook key

For systems that can only be pointed at a URL and cannot set a header:

```php
$sinio = new SinioClient(webhookKey: getenv('SINIO_WEBHOOK_KEY'));
$sinio->sendEvents($events); // POST /v1/webhooks/<key>
```

In this mode only event ingestion is available; the reference endpoints require an
API key.


## Bring your own HTTP client

Implement `Sinio\Http\Sender` to route calls through Guzzle, Symfony HttpClient or
any PSR-18 client the project already has:

```php
use Sinio\Http\{HttpResponse, Sender};

final class GuzzleSender implements Sender
{
    public function __construct(private readonly \GuzzleHttp\Client $http) {}

    public function send(string $url, string $payload, array $headers, float $timeout): HttpResponse
    {
        $response = $this->http->post($url, [
            'body' => $payload, 'headers' => $headers, 'timeout' => $timeout, 'http_errors' => false,
        ]);

        return new HttpResponse(
            $response->getStatusCode(),
            array_change_key_case(array_map(fn ($v) => $v[0], $response->getHeaders())),
            (string) $response->getBody(),
        );
    }
}

$sinio = new SinioClient(apiKey: $key, sender: new GuzzleSender($http));
```

## Development

```bash
composer install
composer test      # phpunit
composer analyse   # phpstan max level, against PHP 8.1
```
