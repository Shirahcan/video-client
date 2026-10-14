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
}
