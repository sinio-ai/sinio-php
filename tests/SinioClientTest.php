<?php

declare(strict_types=1);

namespace Sinio\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sinio\Exception\AuthException;
use Sinio\Exception\RateLimitException;
use Sinio\Exception\RequestException;
use Sinio\Exception\ValidationException;
use Sinio\Model\Assignment;
use Sinio\Model\AssignmentRelation;
use Sinio\Model\Entity;
use Sinio\Model\EntityKind;
use Sinio\Model\Event;
use Sinio\Model\EventRoles;
use Sinio\Model\Location;
use Sinio\Model\LocationLevel;
use Sinio\Model\RejectionCode;
use Sinio\SinioClient;

final class SinioClientTest extends TestCase
{
    /** @var array{0: int, 1: array<string, mixed>} */
    private const OK_EVENTS = [200, [
        'batch_id' => 'b_1',
        'accepted' => 1,
        'pending_classification' => 0,
        'duplicates' => 0,
        'revisions' => 0,
        'rejected' => 0,
        'rejections' => [],
        'test_mode' => false,
    ]];

    private function anEvent(string $sourceEventId = 'evt_1', ?string $project = null): Event
    {
        return new Event(
            sourceEventId: $sourceEventId,
            type: 'badge_in',
            occurredAt: '2026-08-28T07:58:12+02:00',
            payload: ['badge' => '04A2B9C1'],
            project: $project,
        );
    }

    /** @param array<string, mixed> $options */
    private function client(StubSender $sender, array $options = []): SinioClient
    {
        return new SinioClient(...[
            'baseUrl' => 'https://api.test',
            'sender' => $sender,
            'sleep' => static fn (float $seconds) => null,
            ...$options,
        ]);
    }

    public function testSendsABatchWithBearerAuthAndWireFieldNames(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk_test', 'sourceCode' => 'gmao_prod']);

        $event = new Event(
            sourceEventId: 'evt_1',
            type: 'badge_in',
            occurredAt: '2026-08-28T07:58:12+02:00',
            payload: ['badge' => '04A2B9C1'],
            roles: new EventRoles(device: 'BORNE-A12'),
        );
        $result = $client->sendEvents([$event]);

        self::assertSame('https://api.test/v1/events', $sender->calls[0]['url']);
        self::assertSame('Bearer sk_test', $sender->calls[0]['headers']['Authorization']);
        self::assertSame('gmao_prod', $sender->calls[0]['headers']['X-Source-Code']);
        self::assertSame([
            'events' => [[
                'source_event_id' => 'evt_1',
                'type' => 'badge_in',
                'occurred_at' => '2026-08-28T07:58:12+02:00',
                'roles' => ['device' => 'BORNE-A12'],
                'payload' => ['badge' => '04A2B9C1'],
            ]],
            'mode' => 'webhook',
        ], $sender->calls[0]['body']);
        self::assertSame(['b_1'], $result->batchIds);
        self::assertSame(1, $result->accepted);
        self::assertFalse($result->testMode);
    }

    public function testSaysWhenTheKeyIsInTestMode(): void
    {
        $sender = new StubSender([[200, ['test_mode' => true] + self::OK_EVENTS[1]]]);
        $client = $this->client($sender, ['apiKey' => 'sk_test']);

        $result = $client->sendEvents([$this->anEvent()]);

        self::assertTrue($result->testMode);
    }

    public function testSplitsAbove500EventsAndMergesCounters(): void
    {
        $sender = new StubSender([
            [200, [...self::OK_EVENTS[1], 'batch_id' => 'b_1', 'accepted' => 500]],
            [200, [
                'batch_id' => 'b_2',
                'accepted' => 100,
                'pending_classification' => 2,
                'duplicates' => 1,
                'revisions' => 0,
                'rejected' => 1,
                'rejections' => [
                    ['source_event_id' => 'evt_x', 'code' => 'invalid_occurred_at', 'message' => 'nope'],
                ],
            ]],
        ]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $events = [];
        for ($index = 0; $index < 601; ++$index) {
            $events[] = $this->anEvent("evt_{$index}");
        }
        $result = $client->sendEvents($events);

        self::assertCount(2, $sender->calls);
        self::assertCount(500, $sender->calls[0]['body']['events']);
        self::assertCount(101, $sender->calls[1]['body']['events']);
        self::assertSame(600, $result->accepted);
        self::assertSame(2, $result->pendingClassification);
        self::assertSame(['b_1', 'b_2'], $result->batchIds);
        self::assertSame('evt_x', $result->rejections[0]->sourceEventId);
    }

    public function testAppliesTheClientProjectOnlyWhenTheEventHasNone(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk', 'project' => 'client-acme']);

        $client->sendEvents([$this->anEvent(), $this->anEvent('evt_2', 'client-other')]);

        $sent = $sender->calls[0]['body']['events'];
        self::assertSame('client-acme', $sent[0]['project']);
        self::assertSame('client-other', $sent[1]['project']);
    }

    public function testAcceptsADateTimeAndSerialisesAnEmptyPayloadAsAnObject(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $moment = new \DateTimeImmutable('2026-08-28T07:58:12', new \DateTimeZone('+02:00'));
        $client->sendEvents([
            new Event(sourceEventId: 'evt_dt', type: 'badge_in', occurredAt: $moment),
            new Event(sourceEventId: 'evt_empty', type: 'badge_in', occurredAt: $moment, payload: []),
        ]);

        $sent = $sender->calls[0]['body']['events'];
        self::assertSame('2026-08-28T07:58:12+02:00', $sent[0]['occurred_at']);
        // The payload is optional: left out, it never reaches the wire.
        self::assertArrayNotHasKey('payload', $sent[0]);
        // An empty payload must stay a JSON object on the wire; `[]` would fail the API's @IsObject rule.
        self::assertStringContainsString('"payload":{}', $sender->calls[0]['raw']);
    }

    public function testBackfillDeclaresTheBackfillMode(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $client->backfillEvents([$this->anEvent()]);

        self::assertSame('backfill', $sender->calls[0]['body']['mode']);
    }

    public function testWebhookKeyAuthenticatesThroughTheUrlAndBlocksReferenceCalls(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['webhookKey' => 'whk_abc']);

        $client->sendEvents([$this->anEvent()]);

        self::assertSame('https://api.test/v1/webhooks/whk_abc', $sender->calls[0]['url']);
        self::assertArrayNotHasKey('Authorization', $sender->calls[0]['headers']);

        $this->expectException(ValidationException::class);
        $client->upsertEntities([]);
    }

    public function testSendsTheCountAnEventStandsForAndRefusesOneOutOfRange(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $client->sendEvents([new Event(
            sourceEventId: 'evt_1',
            type: 'button_pressed',
            occurredAt: '2026-08-28T07:58:12+02:00',
            count: 10,
        )]);
        self::assertSame(10, $sender->calls[0]['body']['events'][0]['count']);

        foreach ([0, 1_000_001] as $count) {
            try {
                $client->sendEvents([new Event(
                    sourceEventId: 'evt_2',
                    type: 'button_pressed',
                    occurredAt: '2026-08-28T07:58:12+02:00',
                    count: $count,
                )]);
                self::fail('Expected a ValidationException');
            } catch (ValidationException $error) {
                self::assertStringContainsString('count', $error->getMessage());
            }
        }
        self::assertCount(1, $sender->calls);
    }

    public function testRejectsATimestampWithoutOffsetBeforeAnyCall(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        try {
            $client->sendEvents([new Event(
                sourceEventId: 'evt_1',
                type: 'badge_in',
                occurredAt: '2026-08-28T07:58:12',
            )]);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $error) {
            self::assertStringContainsString('offset', $error->getMessage());
        }
        // What the API refuses too: a space for the `T`, no seconds, a lowercase `z`.
        foreach (['2026-08-28 07:58:12Z', '2026-08-28T07:58Z', '2026-08-28T07:58:12z'] as $occurredAt) {
            try {
                $client->sendEvents([new Event(sourceEventId: 'evt_1', type: 'badge_in', occurredAt: $occurredAt)]);
                self::fail("Expected a ValidationException for {$occurredAt}");
            } catch (ValidationException $error) {
                self::assertStringContainsString('offset', $error->getMessage());
            }
        }
        self::assertSame([], $sender->calls);
    }

    public function testUpsertsReferenceDataWithTheProjectAtTheTopLevel(): void
    {
        $sender = new StubSender([[200, ['accepted' => 1, 'rejected' => 0, 'rejections' => []]]]);
        $client = $this->client($sender, ['apiKey' => 'sk', 'project' => 'client-acme']);

        $client->upsertEntities([new Entity(
            externalId: 'BORNE-A12',
            kind: EntityKind::Device,
            subkind: 'borne_nfc',
            attributes: ['firmware' => '2.4.1'],
        )]);
        $client->upsertLocations([new Location(
            externalId: 'SITE-A.HALL',
            kind: LocationLevel::Zone,
            name: 'Hall',
            parent: 'SITE-A',
        )]);

        self::assertSame([
            'project' => 'client-acme',
            'entities' => [[
                'external_id' => 'BORNE-A12',
                'kind' => 'device',
                'subkind' => 'borne_nfc',
                'attributes' => ['firmware' => '2.4.1'],
            ]],
        ], $sender->calls[0]['body']);
        self::assertSame([
            'project' => 'client-acme',
            'locations' => [[
                'external_id' => 'SITE-A.HALL',
                'kind' => 'zone',
                'name' => 'Hall',
                'parent' => 'SITE-A',
            ]],
        ], $sender->calls[1]['body']);
    }

    public function testCreatesAssignmentsAndSurfacesOverlaps(): void
    {
        $sender = new StubSender([[200, [
            'accepted' => 0,
            'rejected' => 1,
            'rejections' => [[
                'credential' => '04A2B9C1',
                'subject' => 'P-4582',
                'code' => 'overlapping_assignment',
                'message' => 'overlap',
            ]],
        ]]]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $result = $client->createAssignments([new Assignment(
            credential: '04A2B9C1',
            subject: 'P-4582',
            validFrom: '2026-01-01T00:00:00+01:00',
            relation: 'assigned_to',
        )]);

        self::assertSame([[
            'credential' => '04A2B9C1',
            'subject' => 'P-4582',
            'relation' => 'assigned_to',
            'valid_from' => '2026-01-01T00:00:00+01:00',
        ]], $sender->calls[0]['body']['assignments']);
        self::assertSame('overlapping_assignment', $result->rejections[0]->code);
        self::assertSame(RejectionCode::OverlappingAssignment, $result->rejections[0]->knownCode);
    }

    /** @return iterable<string, array{0: \Closure(SinioClient): mixed, 1: string}> */
    public static function locallyRefusedInputs(): iterable
    {
        $at = '2026-08-28T07:58:12+02:00';
        $event = static fn (array $fields): \Closure => static fn (SinioClient $client): mixed => $client->sendEvents([
            new Event(...['sourceEventId' => 'evt_1', 'type' => 'badge_in', 'occurredAt' => $at, ...$fields]),
        ]);
        $entity = static fn (array $fields): \Closure => static fn (SinioClient $client): mixed => $client->upsertEntities([
            new Entity(...['externalId' => 'BORNE-A12', 'kind' => EntityKind::Device, ...$fields]),
        ]);
        $location = static fn (array $fields): \Closure => static fn (SinioClient $client): mixed => $client->upsertLocations([
            new Location(...['externalId' => 'SITE-A', 'kind' => LocationLevel::Site, 'name' => 'Site A', ...$fields]),
        ]);
        $assignment = static fn (array $fields): \Closure => static fn (SinioClient $client): mixed => $client->createAssignments([
            new Assignment(...['credential' => '04A2B9C1', 'subject' => 'P-4582', 'validFrom' => $at, ...$fields]),
        ]);

        yield 'event without source_event_id' => [$event(['sourceEventId' => '']), 'events[0].sourceEventId is required'];
        yield 'event without type' => [$event(['type' => '']), 'events[0].type is required'];
        yield 'event without occurred_at' => [$event(['occurredAt' => '']), 'events[0].occurredAt is required'];
        yield 'event with an empty role' => [
            $event(['roles' => new EventRoles(device: 'BORNE-A12', subject: '')]),
            'events[0].roles.subject must not be empty',
        ];
        yield 'event with a list payload' => [
            $event(['payload' => ['04A2B9C1', 'BORNE-A12']]),
            'events[0].payload must be a JSON object',
        ];
        yield 'entity with an unknown kind' => [
            $entity(['kind' => 'devise']),
            'entities[0].kind must be one of device, subject, asset, actor, credential (got "devise")',
        ];
        yield 'entity without external_id' => [$entity(['externalId' => '']), 'entities[0].externalId is required'];
        yield 'entity without kind' => [$entity(['kind' => '']), 'entities[0].kind is required'];
        yield 'entity with an empty subkind' => [$entity(['subkind' => '']), 'entities[0].subkind must not be empty'];
        yield 'entity with list attributes' => [
            $entity(['attributes' => ['2.4.1']]),
            'entities[0].attributes must be a JSON object',
        ];
        yield 'location with an unknown kind' => [
            $location(['kind' => 'room']),
            'locations[0].kind must be one of site, zone, point (got "room")',
        ];
        yield 'location without external_id' => [$location(['externalId' => '']), 'locations[0].externalId is required'];
        yield 'location without kind' => [$location(['kind' => '']), 'locations[0].kind is required'];
        yield 'location without name' => [$location(['name' => '']), 'locations[0].name is required'];
        yield 'assignment with an unknown relation' => [
            $assignment(['relation' => 'owned_by']),
            'assignments[0].relation must be one of assigned_to (got "owned_by")',
        ];
        yield 'assignment without credential' => [$assignment(['credential' => '']), 'assignments[0].credential is required'];
        yield 'assignment without subject' => [$assignment(['subject' => '']), 'assignments[0].subject is required'];
        yield 'assignment without valid_from' => [$assignment(['validFrom' => '']), 'assignments[0].validFrom is required'];
        yield 'assignment with valid_from without offset' => [
            $assignment(['validFrom' => '2026-01-01T00:00:00']),
            'assignments[0].validFrom must be an ISO 8601 timestamp with an offset',
        ];
        yield 'assignment with valid_to without offset' => [
            $assignment(['validTo' => '2026-12-31']),
            'assignments[0].validTo must be an ISO 8601 timestamp with an offset',
        ];
        yield 'assignment with valid_to before valid_from' => [
            $assignment(['validFrom' => '2026-06-01T00:00:00Z', 'validTo' => '2026-01-01T00:00:00Z']),
            'assignments[0].validTo must be after validFrom',
        ];
        // The same instant written in two offsets is an empty range, refused by the API as well.
        yield 'assignment with valid_to equal to valid_from' => [
            $assignment(['validFrom' => '2026-06-01T02:00:00+02:00', 'validTo' => '2026-06-01T00:00:00Z']),
            'assignments[0].validTo must be after validFrom',
        ];
    }

    /** @param \Closure(SinioClient): mixed $call */
    #[DataProvider('locallyRefusedInputs')]
    public function testRefusesLocallyWhatTheApiWouldRefuse(\Closure $call, string $message): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        try {
            $call($client);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame([], $sender->calls);
    }

    public function testNamesTheIndexOfTheRefusedItem(): void
    {
        $sender = new StubSender([self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('entities[3].kind must be one of device, subject, asset, actor, credential (got "devise")');
        $client->upsertEntities([
            new Entity(externalId: 'A', kind: EntityKind::Device),
            new Entity(externalId: 'B', kind: 'subject'),
            new Entity(externalId: 'C', kind: EntityKind::Asset),
            new Entity(externalId: 'D', kind: 'devise'),
        ]);
    }

    public function testAcceptsVocabularyStringsAndEnumsAndAValidRange(): void
    {
        $sender = new StubSender([[200, ['accepted' => 1, 'rejected' => 0, 'rejections' => []]]]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $client->upsertEntities([new Entity(externalId: 'P-4582', kind: 'subject')]);
        $client->upsertLocations([new Location(externalId: 'SITE-A', kind: 'site', name: 'Site A')]);
        $client->createAssignments([
            new Assignment(
                credential: '04A2B9C1',
                subject: 'P-4582',
                validFrom: '2026-06-01T02:00:00+02:00',
                validTo: '2026-06-01T00:00:00.001Z',
                relation: AssignmentRelation::AssignedTo,
            ),
            new Assignment(credential: '04A2B9C2', subject: 'P-4583', validFrom: '2026-06-01T00:00:00Z', relation: 'assigned_to'),
        ]);

        self::assertSame('subject', $sender->calls[0]['body']['entities'][0]['kind']);
        self::assertSame('site', $sender->calls[1]['body']['locations'][0]['kind']);
        self::assertSame('assigned_to', $sender->calls[2]['body']['assignments'][0]['relation']);
        self::assertSame('assigned_to', $sender->calls[2]['body']['assignments'][1]['relation']);
        self::assertSame('2026-06-01T00:00:00.001Z', $sender->calls[2]['body']['assignments'][0]['valid_to']);
    }

    public function testKeepsAnUnknownRejectionCodeWithoutFailing(): void
    {
        $sender = new StubSender([[200, [
            ...self::OK_EVENTS[1],
            'accepted' => 0,
            'rejected' => 2,
            'rejections' => [
                ['source_event_id' => 'evt_1', 'code' => 'quota_exceeded', 'message' => 'new in the API', 'hint' => 'x'],
                ['source_event_id' => 'evt_2', 'code' => 'invalid_occurred_at', 'message' => 'nope'],
            ],
            'some_future_field' => ['nested' => true],
        ]]]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $result = $client->sendEvents([$this->anEvent('evt_1'), $this->anEvent('evt_2')]);

        self::assertSame('quota_exceeded', $result->rejections[0]->code);
        self::assertNull($result->rejections[0]->knownCode);
        self::assertSame(RejectionCode::InvalidOccurredAt, $result->rejections[1]->knownCode);
    }

    public function testRetriesA503ThenSucceeds(): void
    {
        $sender = new StubSender([[503, ['message' => 'down']], self::OK_EVENTS]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        $result = $client->sendEvents([$this->anEvent()]);

        self::assertCount(2, $sender->calls);
        self::assertSame(1, $result->accepted);
    }

    public function testSurfacesA429WithItsRetryAfter(): void
    {
        $sender = new StubSender([[429, ['message' => 'slow down'], ['retry-after' => '12']]]);
        $client = $this->client($sender, ['apiKey' => 'sk', 'maxRetries' => 1]);

        try {
            $client->sendEvents([$this->anEvent()]);
            self::fail('Expected a RateLimitException');
        } catch (RateLimitException $error) {
            self::assertSame(12.0, $error->retryAfterSeconds);
        }
        self::assertCount(2, $sender->calls);
    }

    public function testDoesNotRetryA401AndJoinsValidationMessages(): void
    {
        $sender = new StubSender([[401, ['message' => 'Invalid or revoked API key']]]);
        $client = $this->client($sender, ['apiKey' => 'sk']);

        try {
            $client->sendEvents([$this->anEvent()]);
            self::fail('Expected an AuthException');
        } catch (AuthException $error) {
            self::assertSame(401, $error->status);
        }
        self::assertCount(1, $sender->calls);

        $other = $this->client(
            new StubSender([[400, ['message' => ['type must be a string', 'payload must be an object']]]]),
            ['apiKey' => 'sk'],
        );
        $this->expectException(RequestException::class);
        $this->expectExceptionMessageMatches('/payload must be an object/');
        $other->sendEvents([$this->anEvent()]);
    }

    public function testRequiresExactlyOneCredential(): void
    {
        $this->expectException(ValidationException::class);
        new SinioClient();
    }

    public function testRejectsBothCredentialsAtOnce(): void
    {
        $this->expectException(ValidationException::class);
        new SinioClient(apiKey: 'sk', webhookKey: 'whk');
    }
}
