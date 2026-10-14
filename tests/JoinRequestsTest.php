<?php

namespace Shirahcan\VideoClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\Exceptions\VideoRequestRejected;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\JoinRequest;
use Shirahcan\VideoClient\VideoServiceClient;

/** Asking to join by an "anyone with the link" link, and the host's answer (plan M7j). */
class JoinRequestsTest extends TestCase
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

    public function test_the_client_asks_reads_and_decides(): void
    {
        $row = ['id' => 'r-1', 'call_ref' => 'b-1', 'name' => 'Ada', 'status' => 'waiting', 'created_at' => '2026-10-14T10:00:00+00:00'];
        $client = $this->client(
            $this->json(201, ['success' => true, 'data' => $row]),
            $this->json(200, ['success' => true, 'data' => ['requests' => [$row]]]),
            $this->json(200, ['success' => true, 'data' => ['status' => 'admitted'] + $row]),
            $this->json(404, ['success' => false, 'error' => 'link_not_found', 'message' => 'gone']),
        );

        $asked = $client->askToJoin('tok', 'Ada');
        $this->assertSame('b-1', $asked->callRef);
        $this->assertSame(JoinRequest::WAITING, $asked->status);
        $this->assertSame('{"token":"tok","name":"Ada"}', (string) $this->history[0]['request']->getBody());

        $this->assertCount(1, $client->joinRequests('b-1'));
        $this->assertSame('api/v1/calls/b-1/join-requests', ltrim($this->history[1]['request']->getUri()->getPath(), '/'));

        $this->assertSame(JoinRequest::ADMITTED, $client->decideJoinRequest('r-1', true, 'mploynow:user:9')->status);
        $this->assertSame('{"admit":true,"actor":"mploynow:user:9"}', (string) $this->history[2]['request']->getBody());

        $this->assertNull($client->askToJoin('dead', 'Ada'));
    }

    public function test_the_fake_admits_once_and_only_by_an_active_link(): void
    {
        $fake = new FakeVideoClient();
        $link = $fake->issueJoinLinks('b-1', [['audience' => 'anyone']])[0];

        $asked = $fake->askToJoin($link->token, 'Ada');
        $this->assertCount(1, $fake->joinRequests('b-1'));
        $this->assertSame(JoinRequest::ADMITTED, $fake->decideJoinRequest($asked->id, true)->status);
        $this->assertSame(JoinRequest::ADMITTED, $fake->joinRequest($asked->id)->status);
        $this->assertCount(0, $fake->joinRequests('b-1'));

        $this->expectException(VideoRequestRejected::class);
        try {
            $fake->decideJoinRequest($asked->id, false);
        } finally {
            $fake->revokeJoinLinks('b-1', 'cancelled');
            $this->assertNull($fake->askToJoin($link->token, 'Ada'));
        }
    }
}
