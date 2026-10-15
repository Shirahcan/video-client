<?php

namespace Shirahcan\VideoClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\Laravel\Presence\CallLive;

class CallLiveTest extends TestCase
{
    public function test_who_is_in_now_and_who_ever_came_asked_once_for_a_list(): void
    {
        $video = new FakeVideoClient('mploynow');
        $now = new \DateTimeImmutable();
        $video->callPresence('call-1', 'host-1', 'join', $now->modify('-10 minutes'));
        $video->callPresence('call-1', 'host-1', 'leave', $now->modify('-9 minutes'));
        $video->callPresence('call-1', 'cand-1', 'heartbeat', $now->modify('-30 seconds'));
        $live = new CallLive($video, fn () => null);

        $live->preload(['call-1', 'call-2', null]);

        $this->assertTrue($live->isLive('call-1'));
        $this->assertSame(['cand-1'], $live->inNow('call-1'));
        $this->assertEqualsCanonicalizing(['host-1', 'cand-1'], $live->seen('call-1'));
        $this->assertFalse($live->isLive('call-2'));
        $this->assertNull($live->startedAt('call-2'));
        $this->assertCount(1, array_filter($video->calls, fn ($c) => $c[0] === 'callSummaries'), 'one request for the list');
    }

    public function test_an_outage_reads_as_unknown_and_is_reported(): void
    {
        $video = new class('mploynow') extends FakeVideoClient {
            public function callSummaries(array $callRefs): array
            {
                throw new VideoServiceUnavailable('down', 'unavailable', 503);
            }
        };
        $reported = [];
        $live = new CallLive($video, function (\Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        $this->assertNull($live->summary('call-1'));
        $this->assertSame([], $live->inNow('call-1'));
        $this->assertCount(1, $reported);
    }

    public function test_the_fake_judges_a_call_a_host_ended_at_once_as_the_service_does(): void
    {
        $video = new FakeVideoClient('mploynow');
        $starts = new \DateTimeImmutable('-10 minutes');
        $ends = $starts->modify('+30 minutes');
        $room = $video->createRoom('call-7', $starts, $ends);
        $video->callPresence('call-7', 'host-1', 'join', $starts);
        $video->callPresence('call-7', 'cand-1', 'join', $starts);

        // Twenty booked minutes left: not judged yet.
        $this->assertSame(\Shirahcan\VideoClient\VideoVerdict::NOT_YET, $video->callVerdict('call-7', ['host-1'], ['cand-1'], $ends)->verdict);

        // A host ended it: closed now, judged now (video-service CallState: ended = closed).
        $video->endRoom($room->name, 'host-1');
        $this->assertSame(\Shirahcan\VideoClient\VideoVerdict::HELD, $video->callVerdict('call-7', ['host-1'], ['cand-1'], $ends)->verdict);
    }

    public function test_the_fake_tells_apart_when_asked_together(): void
    {
        $video = new FakeVideoClient('mploynow');
        $t = new \DateTimeImmutable('-3 hours');
        $video->callPresence('call-9', 'cand-1', 'join', $t);
        $video->callPresence('call-9', 'cand-1', 'leave', $t->modify('+5 minutes'));
        $video->callPresence('call-9', 'host-1', 'join', $t->modify('+20 minutes'));
        $video->callPresence('call-9', 'host-1', 'heartbeat', $t->modify('+30 minutes'));
        $ends = $t->modify('+30 minutes');

        $this->assertSame(\Shirahcan\VideoClient\VideoVerdict::APART, $video->callVerdict('call-9', ['host-1'], ['cand-1'], $ends, true)->verdict);
        $this->assertSame(\Shirahcan\VideoClient\VideoVerdict::HELD, $video->callVerdict('call-9', ['host-1'], ['cand-1'], $ends)->verdict);
    }

    public function test_a_client_that_cannot_be_made_reads_as_unknown(): void
    {
        $reported = [];
        $live = new CallLive(function () {
            throw new \RuntimeException('video-client: VIDEO_SERVICE_TRUST_KEY is not set.');
        }, function (\Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        $this->assertFalse($live->isLive('call-1'));
        $this->assertSame([], $live->seen('call-1'));
        $this->assertCount(1, $reported);
    }

    public function test_the_fake_deletes_only_that_calls_transcripts(): void
    {
        $video = new FakeVideoClient('mploynow');
        $video->captured('call-1', 'Host: hello');
        $video->captured('call-2', 'Host: hi');

        $this->assertSame(1, $video->deleteTranscripts('call-1'));
        $this->assertSame([], $video->callTranscripts('call-1'));
        $this->assertCount(1, $video->callTranscripts('call-2'));
    }

    public function test_a_captured_transcript_with_its_own_id_can_be_cleaned(): void
    {
        $video = new FakeVideoClient('mploynow');
        $t = $video->captured('call-1', 'Host: hello', ['id' => 'tr-9']);

        $this->assertSame('Tidy.', $video->saveCleanText($t->id, 'Tidy.')->cleanText);
        $this->assertSame('Tidy.', $video->callTranscripts('call-1')[0]->cleanText);
    }
}
