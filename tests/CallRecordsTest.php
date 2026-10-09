<?php

namespace Shirahcan\VideoClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\CallTranscript;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\VideoServiceClient;

/** A call's kept transcripts and join issues (owner 2026-10-09), by the product's call reference. */
class CallRecordsTest extends TestCase
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

    public function test_transcripts_are_read_by_the_call(): void
    {
        $list = $this->client($this->json(200, ['success' => true, 'data' => ['transcripts' => [
            ['id' => '7', 'call_ref' => 'booking-1', 'source' => 'captured', 'status' => 'ready', 'text' => 'Maria: hi', 'clean_text' => 'Maria said hi.'],
        ]]]))->callTranscripts('booking-1');

        $this->assertSame('/api/v1/calls/booking-1/transcripts', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('Maria said hi.', $list[0]->bestText());
        $this->assertTrue($list[0]->isReady());
    }

    public function test_cleaned_text_is_always_sent_even_when_clearing(): void
    {
        $this->client($this->json(200, ['success' => true, 'data' => ['id' => '7', 'call_ref' => 'b', 'source' => 'captured', 'status' => 'ready']]))
            ->saveCleanText('7', null);

        $request = $this->history[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(['clean_text' => null], json_decode((string) $request->getBody(), true));
    }

    public function test_a_join_issue_is_posted_without_empty_fields(): void
    {
        $issue = $this->client($this->json(201, ['success' => true, 'data' => ['id' => '1', 'call_ref' => 'booking-1', 'category' => 'device', 'kind' => 'in-use']]))
            ->reportJoinIssue('booking-1', ['category' => 'device', 'kind' => 'in-use', 'device' => null]);

        $this->assertSame('in-use', $issue->kind);
        $this->assertSame(['category' => 'device', 'kind' => 'in-use'], json_decode((string) $this->history[0]['request']->getBody(), true));
    }

    public function test_the_fake_keeps_one_record_per_call_and_replaces_a_failed_one(): void
    {
        $fake = new FakeVideoClient();
        $fake->captured('booking-1', 'Maria: hi', ['status' => 'error', 'text' => null]);
        $supplied = $fake->supplyTranscript('booking-1', 'We spoke by phone.', null, 'user-1');

        $this->assertCount(1, $fake->callTranscripts('booking-1'));
        $this->assertSame(CallTranscript::SUPPLIED, $supplied->source);
        $this->assertSame('Tidy.', $fake->saveCleanText($supplied->id, 'Tidy.')->cleanText);

        $fake->reportJoinIssue('booking-1', ['category' => 'call', 'kind' => 'network']);
        $this->assertSame('network', $fake->joinIssues('booking-1')[0]->kind);
        $this->assertSame([], $fake->joinIssues('booking-2'));
    }
}
