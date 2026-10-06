<?php

namespace Shirahcan\VideoClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\Exceptions\RoomNotFound;
use Shirahcan\VideoClient\Exceptions\RoomStranded;
use Shirahcan\VideoClient\Exceptions\VideoOverBudget;
use Shirahcan\VideoClient\Exceptions\VideoRequestRejected;
use Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\VideoServiceClient;
use Shirahcan\VideoClient\WebhookSignature;

class ClientTest extends TestCase
{
    /** @var array<int, array> */
    private array $history = [];

    private function client(Response ...$responses): VideoServiceClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new VideoServiceClient('http://127.0.0.1:8008', 'test-key', 15,
            new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8008/']));
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_create_room_returns_a_room_and_sends_no_domain_or_key(): void
    {
        $room = $this->client($this->json(201, ['success' => true, 'data' => [
            'name' => 'portify-prod-m-1-abcd1234', 'external_ref' => 'm-1', 'domain' => 'shirah', 'status' => 'active',
        ]]))->createRoom('m-1', new \DateTimeImmutable('2026-11-02 15:00Z'), new \DateTimeImmutable('2026-11-02 15:30Z'), ['transcription' => true]);

        $this->assertSame('portify-prod-m-1-abcd1234', $room->name);

        $request = $this->history[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertSame(['external_ref', 'starts_at', 'ends_at', 'transcription'], array_keys($body));
        $this->assertStringNotContainsString('daily', strtolower((string) $request->getBody()));
    }

    public function test_repair_sends_the_products_close_and_revive_only_when_asked(): void
    {
        $room = ['name' => 'portify-prod-m-1-abcd1234', 'external_ref' => 'm-1', 'domain' => 'shirah', 'status' => 'active'];
        $client = $this->client(
            $this->json(200, ['success' => true, 'data' => $room + ['issues' => [], 'actions' => []]]),
            $this->json(200, ['success' => true, 'data' => $room + ['issues' => ['missing_on_daily'], 'actions' => ['rebuilt on shirah']]]),
        );

        $client->repairRoom('portify-prod-m-1-abcd1234');
        $result = $client->repairRoom('portify-prod-m-1-abcd1234', new \DateTimeImmutable('2026-11-02T16:10:00Z'), true);

        $this->assertSame('', (string) $this->history[0]['request']->getBody());
        $this->assertSame(['joinable_until' => '2026-11-02T16:10:00+00:00', 'revive' => true], json_decode((string) $this->history[1]['request']->getBody(), true));
        $this->assertSame(['rebuilt on shirah'], $result['actions']);
    }

    public function test_the_token_carries_the_url_and_the_call_state(): void
    {
        $token = $this->client($this->json(201, ['data' => [
            'token' => 'T', 'url' => 'https://shirah.daily.co/r?t=T', 'expires_at' => 'x',
            'call' => ['state' => 'grace', 'closes_at' => '2026-11-02T16:00:00+00:00', 'server_time' => '2026-11-02T15:40:00+00:00'],
        ]]))->token('r', 'u-1', 'Maria', false);

        $this->assertSame('https://shirah.daily.co/r?t=T', $token->url);
        $this->assertTrue($token->call?->isJoinable());
        $this->assertSame('2026-11-02T16:00:00+00:00', $token->call->closesAt);
    }

    public function test_attendance_is_keyed_by_participant_and_an_outage_throws(): void
    {
        $seen = $this->client($this->json(200, ['data' => [
            'room' => 'r', 'sessions' => 1, 'ongoing' => false, 'anonymous_seconds' => 0,
            'participants' => [['participant_id' => 'u-1', 'first_joined_at' => '2026-11-02T15:01:00+00:00', 'seconds' => 1500]],
        ]]))->attendance('r');

        $this->assertTrue($seen->attended('u-1'));
        $this->assertFalse($seen->attended('u-2'));
        $this->assertFalse($seen->isEmpty());
        $this->assertStringEndsWith('/api/v1/rooms/r/attendance', (string) $this->history[0]['request']->getUri());

        $this->expectException(VideoServiceUnavailable::class);
        $this->client($this->json(503, ['success' => false, 'code' => 'daily_unavailable', 'message' => 'down']))->attendance('r');
    }

    /** One exception per remedy. */
    public function test_each_error_code_maps_to_its_own_exception(): void
    {
        $cases = [
            [VideoOverBudget::class, 429, 'over_budget'],
            [RoomStranded::class, 409, 'room_stranded'],
            [RoomNotFound::class, 404, 'room_not_found'],
            [VideoServiceUnavailable::class, 503, 'daily_unavailable'],
            [VideoRequestRejected::class, 502, 'daily_rejected'],
            [VideoRequestRejected::class, 422, null],
            [VideoServiceUnavailable::class, 401, 'unauthorized'],
        ];

        foreach ($cases as [$class, $status, $code]) {
            try {
                $this->client($this->json($status, array_filter(['error' => $code, 'message' => 'm'])))->room('r');
                $this->fail("{$status} {$code} did not throw");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e, "{$status} {$code}");
            }
        }
    }

    public function test_an_unreachable_service_is_unavailable(): void
    {
        $stack = HandlerStack::create(new MockHandler([new \GuzzleHttp\Exception\ConnectException('refused', new \GuzzleHttp\Psr7\Request('GET', 'x'))]));
        $client = new VideoServiceClient('http://127.0.0.1:8008', 'k', 15, new Guzzle(['handler' => $stack]));

        $this->expectException(VideoServiceUnavailable::class);
        $client->room('r');
    }

    public function test_delete_of_a_missing_room_is_success(): void
    {
        $this->client($this->json(404, ['error' => 'room_not_found']))->deleteRoom('gone');
        $this->assertCount(1, $this->history);
    }

    public function test_the_pipe_forwards_raw_bytes_and_daily_headers_unchanged(): void
    {
        $raw = '{"type":"participant.joined","payload":{"room":"r"}}';

        $this->client($this->json(202, ['data' => ['event_id' => 'e', 'duplicate' => false]]))
            ->forwardDailyWebhook($raw, ['x-webhook-signature' => ['SIG'], 'x-webhook-timestamp' => ['123'], 'host' => ['evil']]);

        $request = $this->history[0]['request'];
        $this->assertSame($raw, (string) $request->getBody());
        $this->assertSame('SIG', $request->getHeaderLine('X-Webhook-Signature'));
        $this->assertSame('123', $request->getHeaderLine('X-Webhook-Timestamp'));
    }

    public function test_callback_signatures_verify_and_reject_tampering_and_replay(): void
    {
        $secret = base64_encode('cb-secret');
        $body = '{"event":"participant.joined"}';
        $ts = '1790000000';
        $sig = base64_encode(hash_hmac('sha256', "{$ts}.{$body}", 'cb-secret', true));

        $this->assertTrue(WebhookSignature::verify($body, $sig, $ts, $secret, 1790000100));
        $this->assertFalse(WebhookSignature::verify($body.' ', $sig, $ts, $secret, 1790000100));
        $this->assertFalse(WebhookSignature::verify($body, $sig, $ts, $secret, 1790000000 + 200000));
        $this->assertFalse(WebhookSignature::verify($body, $sig, $ts, '', 1790000100));
    }

    public function test_the_fake_behaves_like_the_service(): void
    {
        $fake = new FakeVideoClient('portify');
        $a = $fake->createRoom('m-1', new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour'));
        $b = $fake->createRoom('m-1', new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour'));

        $this->assertSame($a->name, $b->name);
        $this->assertStringContainsString('?t=', $fake->token($a->name, 'u', 'X', true)->url);
        $this->assertNotSame($fake->token($a->name, 'u', 'X', true)->token, $fake->token($a->name, 'v', 'Y', false)->token);

        $fake->failNext(new VideoServiceUnavailable('down'));
        $this->expectException(VideoServiceUnavailable::class);
        $fake->room($a->name);
    }
}
