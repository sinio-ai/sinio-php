<?php

declare(strict_types=1);

namespace Sinio;

use Sinio\Exception\ValidationException;
use Sinio\Http\CurlSender;
use Sinio\Http\Sender;
use Sinio\Http\Transport;
use Sinio\Internal\Json;
use Sinio\Internal\Serializer;
use Sinio\Model\Assignment;
use Sinio\Model\AssignmentRejection;
use Sinio\Model\AssignmentsResult;
use Sinio\Model\Entity;
use Sinio\Model\Event;
use Sinio\Model\EventRejection;
use Sinio\Model\IngestionMode;
use Sinio\Model\Location;
use Sinio\Model\ReferenceRejection;
use Sinio\Model\SendEventsResult;
use Sinio\Model\UpsertResult;

/**
 * Client for the sinio.ai ingestion API.
 *
 * Every method accepts an arbitrarily long list and splits it into calls of
 * 500 items, the API limit, merging the counters and rejections back into a
 * single result. Sending the same events twice is safe: idempotency is keyed
 * on `(project, source, source_event_id, revision)`, so a replay is counted as
 * a duplicate rather than stored again.
 */
final class SinioClient
{
    public const VERSION = '0.2.0';
    public const DEFAULT_BASE_URL = 'https://api.sinio.ai';

    private readonly Transport $transport;
    private readonly bool $webhookMode;
    private readonly string $eventsPath;

    /** @var array<string, string> */
    private readonly array $authHeaders;

    /**
     * @param string|null $apiKey machine credential, sent as `Authorization: Bearer`
     * @param string|null $webhookKey key carried in the URL instead of a header, for systems that can only
     *                                be pointed at a webhook URL; only `sendEvents()` is available in this
     *                                mode, and it is mutually exclusive with `$apiKey`
     * @param string|null $sourceCode sent as `X-Source-Code`; optional when the key is already bound to a source,
     *                                needed for keys covering several sources, such as organization-wide keys
     *                                (issued on request)
     * @param string|null $project default project `external_id`; only for organization-wide keys (issued on
     *                             request), which cover several projects; a project key already carries its project
     * @param (callable(float): void)|null $sleep injectable sleep, so tests do not wait on retries
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $webhookKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly ?string $sourceCode = null,
        private readonly ?string $project = null,
        float $timeout = 30.0,
        int $maxRetries = 3,
        float $retryBaseDelay = 0.5,
        Sender $sender = new CurlSender(),
        ?callable $sleep = null,
    ) {
        $hasApiKey = $apiKey !== null && $apiKey !== '';
        $hasWebhookKey = $webhookKey !== null && $webhookKey !== '';
        if ($hasApiKey === $hasWebhookKey) {
            throw new ValidationException('Provide exactly one of $apiKey or $webhookKey.');
        }

        $this->webhookMode = $hasWebhookKey;
        $this->authHeaders = $hasApiKey ? ['Authorization' => "Bearer {$apiKey}"] : [];
        $this->eventsPath = $hasWebhookKey
            ? '/v1/webhooks/' . rawurlencode((string) $webhookKey)
            : '/v1/events';
        $this->transport = new Transport(
            $baseUrl,
            $timeout,
            $maxRetries,
            $retryBaseDelay,
            'sinio-php/' . self::VERSION,
            $sender,
            $sleep,
        );
    }

    /**
     * `POST /v1/events` -- live ingestion.
     *
     * Derivation is asynchronous: a 200 confirms receipt, not derivation. Within
     * a batch, valid events are accepted even when others are rejected, so read
     * `$result->rejections`.
     *
     * Each call gets a `batch_id` from sinio.ai, collected in `$result->batchIds`: quote it to support.
     *
     * @param list<Event> $events
     * @param string|null $sourceCode overrides the client's `$sourceCode` for this call (see the constructor)
     */
    public function sendEvents(
        array $events,
        IngestionMode $mode = IngestionMode::Webhook,
        ?string $sourceCode = null,
    ): SendEventsResult {
        $wire = [];
        foreach ($events as $index => $event) {
            $wire[] = Serializer::event($this->withDefaultProject($event), $index);
        }

        $headers = $this->authHeaders;
        $effectiveSource = $sourceCode ?? $this->sourceCode;
        if ($effectiveSource !== null) {
            $headers['X-Source-Code'] = $effectiveSource;
        }

        $merged = new SendEventsResult();
        foreach (Serializer::chunked($wire, Serializer::MAX_ITEMS_PER_CALL) as $part) {
            $body = ['events' => $part, 'mode' => $mode->value];
            $response = $this->transport->post($this->eventsPath, $body, $headers);

            $rejections = [];
            foreach (Json::objects($response, 'rejections') as $row) {
                $rejections[] = new EventRejection(
                    self::str($row, 'source_event_id'),
                    self::str($row, 'code', 'unknown'),
                    self::str($row, 'message'),
                );
            }
            $batchIds = is_string($response['batch_id'] ?? null) ? [$response['batch_id']] : [];

            $merged = $merged->merge(new SendEventsResult(
                $batchIds,
                self::int($response, 'accepted'),
                self::int($response, 'pending_classification'),
                self::int($response, 'duplicates'),
                self::int($response, 'revisions'),
                self::int($response, 'rejected'),
                $rejections,
                ($response['test_mode'] ?? false) === true,
            ));
        }

        return $merged;
    }

    /**
     * Same endpoint with `mode = backfill`.
     *
     * History is persisted but nothing is alerted; derivation is replayed later
     * in a canonical order. Live catch-up after an outage should stay on
     * `sendEvents()` -- the declared mode decides alerting, not the age of the
     * events.
     *
     * @param list<Event> $events
     */
    public function backfillEvents(
        array $events,
        ?string $sourceCode = null,
    ): SendEventsResult {
        return $this->sendEvents($events, IngestionMode::Backfill, $sourceCode);
    }

    /**
     * `POST /v1/entities` -- bulk upsert keyed on `(kind, external_id)`.
     *
     * An unknown `subkind` never fails the call: it is created undeclared, for
     * someone to qualify in the console.
     *
     * @param list<Entity> $entities
     * @param string|null $project overrides the client's `$project`; organization-wide keys only
     */
    public function upsertEntities(array $entities, ?string $project = null): UpsertResult
    {
        $wire = [];
        foreach ($entities as $index => $entity) {
            $wire[] = Serializer::entity($entity, $index);
        }

        return $this->upsertReference('/v1/entities', 'entities', $wire, $project);
    }

    /**
     * `POST /v1/locations` -- bulk upsert of the location tree, keyed on `external_id`.
     *
     * @param list<Location> $locations
     * @param string|null $project overrides the client's `$project`; organization-wide keys only
     */
    public function upsertLocations(array $locations, ?string $project = null): UpsertResult
    {
        $wire = [];
        foreach ($locations as $index => $location) {
            $wire[] = Serializer::location($location, $index);
        }

        return $this->upsertReference('/v1/locations', 'locations', $wire, $project);
    }

    /**
     * `POST /v1/assignments` -- `credential -> subject` with a validity range.
     *
     * An overlap is rejected with `overlapping_assignment`, never merged silently.
     *
     * @param list<Assignment> $assignments
     * @param string|null $project overrides the client's `$project`; organization-wide keys only
     */
    public function createAssignments(array $assignments, ?string $project = null): AssignmentsResult
    {
        $this->assertBearerMode('createAssignments');
        $wire = [];
        foreach ($assignments as $index => $assignment) {
            $wire[] = Serializer::assignment($assignment, $index);
        }

        $merged = new AssignmentsResult();
        foreach (Serializer::chunked($wire, Serializer::MAX_ITEMS_PER_CALL) as $part) {
            $response = $this->transport->post(
                '/v1/assignments',
                $this->withProject(['assignments' => $part], $project),
                $this->authHeaders,
            );

            $rejections = [];
            foreach (Json::objects($response, 'rejections') as $row) {
                $rejections[] = new AssignmentRejection(
                    self::str($row, 'credential'),
                    self::str($row, 'subject'),
                    self::str($row, 'code', 'unknown'),
                    self::str($row, 'message'),
                );
            }

            $merged = $merged->merge(new AssignmentsResult(
                self::int($response, 'accepted'),
                self::int($response, 'rejected'),
                $rejections,
            ));
        }

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $wire
     */
    private function upsertReference(string $path, string $key, array $wire, ?string $project): UpsertResult
    {
        $this->assertBearerMode($path);

        $merged = new UpsertResult();
        foreach (Serializer::chunked($wire, Serializer::MAX_ITEMS_PER_CALL) as $part) {
            $response = $this->transport->post(
                $path,
                $this->withProject([$key => $part], $project),
                $this->authHeaders,
            );

            $rejections = [];
            foreach (Json::objects($response, 'rejections') as $row) {
                $rejections[] = new ReferenceRejection(
                    self::str($row, 'external_id'),
                    self::str($row, 'code', 'unknown'),
                    self::str($row, 'message'),
                );
            }

            $merged = $merged->merge(new UpsertResult(
                self::int($response, 'accepted'),
                self::int($response, 'rejected'),
                $rejections,
            ));
        }

        return $merged;
    }

    private function withDefaultProject(Event $event): Event
    {
        if ($this->project === null || $event->project !== null) {
            return $event;
        }

        return $event->withProject($this->project);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function withProject(array $body, ?string $project): array
    {
        $effective = $project ?? $this->project;

        return $effective === null ? $body : ['project' => $effective, ...$body];
    }

    private function assertBearerMode(string $operation): void
    {
        if ($this->webhookMode) {
            throw new ValidationException(
                "{$operation} requires an API key: a webhook key only authenticates event ingestion."
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $response */
    private static function int(array $response, string $key): int
    {
        $value = $response[$key] ?? null;

        return is_int($value) ? $value : 0;
    }
}
