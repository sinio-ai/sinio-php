<?php

declare(strict_types=1);

namespace Sinio\Tests;

use PHPUnit\Framework\TestCase;
use Sinio\Exception\AuthException;
use Sinio\Exception\RateLimitException;
use Sinio\Exception\RequestException;
use Sinio\Exception\ValidationException;
use Sinio\Model\Assignment;
use Sinio\Model\Entity;
use Sinio\Model\EntityKind;
use Sinio\Model\Event;
use Sinio\Model\EventRoles;
use Sinio\Model\Location;
use Sinio\Model\LocationLevel;
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
