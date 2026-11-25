<?php

namespace Shirahcan\VideoClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\Laravel\Presence\CallPresenceReceived;
use Shirahcan\VideoClient\Laravel\Presence\PresenceParticipant;
use Shirahcan\VideoClient\Laravel\Presence\RelayPresence;

class RelayPresenceTest extends TestCase
{
    private FakeVideoClient $video;
    private array $events = [];

    private function relay(): RelayPresence
    {
        $this->video ??= new FakeVideoClient('portify');

        return new RelayPresence($this->video, function (object $e) {
            $this->events[] = $e;
        });
    }

    public function test_a_join_or_heartbeat_reaches_the_service_and_the_product(): void
    {
        $this->video = new FakeVideoClient('portify');
        $who = new PresenceParticipant('m-1', 'u-1', 'room-1');

        $this->relay()->relay($who, 'join');
        $this->relay()->relay($who, 'heartbeat');

        $this->assertSame([['room-1', 'u-1'], ['room-1', 'u-1']], $this->video->presence);
        $this->assertCount(2, $this->events);
        $this->assertInstanceOf(CallPresenceReceived::class, $this->events[0]);
        $this->assertSame('join', $this->events[0]->kind);
    }

    public function test_a_leave_is_only_the_products(): void
    {
        $this->video = new FakeVideoClient('portify');
        $this->relay()->relay(new PresenceParticipant('m-1', 'u-1', 'room-1'), 'leave');

        $this->assertSame([], $this->video->presence);
        $this->assertSame('leave', $this->events[0]->kind);
    }

    public function test_no_room_or_a_service_outage_still_tells_the_product(): void
    {
        $this->video = new FakeVideoClient('portify');
        $this->relay()->relay(new PresenceParticipant('m-1', 'u-1', null), 'heartbeat');
        $this->video->failNext(new VideoServiceUnavailable('down', 'service_unavailable', 503));
        $this->relay()->relay(new PresenceParticipant('m-1', 'u-1', 'room-1'), 'heartbeat');

        $this->assertSame([], $this->video->presence);
        $this->assertCount(2, $this->events);
    }

    public function test_with_a_call_ref_every_signal_reaches_the_service_by_the_call_room_or_not(): void
    {
        $this->video = new FakeVideoClient("portify");
        $who = new PresenceParticipant("m-1", "u-1", null, [], "booking-9");

        $this->relay()->relay($who, "join");
        $this->relay()->relay($who, "leave");

        $this->assertSame(["join", "leave"], array_column($this->video->callPresence, 2));
        $this->assertSame([], $this->video->presence, "never twice");
        $this->assertFalse($this->video->callSummaries(["booking-9"])["booking-9"]->live, "left");
        $this->assertCount(2, $this->events);
    }

    /** Call continuity: a device's signal answers what that device should know; a check tells the product nothing. */
    public function test_a_device_signal_returns_the_services_answer_and_a_check_is_not_presence(): void
    {
        $this->video = new FakeVideoClient('portify');
        $this->video->deviceAnswer = ['elsewhere' => ['since' => '2026-11-25T15:02:00+00:00'], 'superseded' => false];
        $who = new PresenceParticipant('m-1', 'u-1', null, [], 'call-ref-1');

        $answer = $this->relay()->relay($who, 'check', null, 'phone');
        $this->assertSame('2026-11-25T15:02:00+00:00', $answer['elsewhere']['since']);
        $this->assertSame([], $this->events, 'a check is a question, not presence');
        $this->assertSame([], $this->video->callPresence);

        $this->relay()->relay($who, 'join', null, 'phone', true);
        $this->assertCount(1, $this->events);
        $this->assertCount(1, $this->video->callPresence);
    }

    public function test_without_a_device_nothing_changes(): void
    {
        $this->video = new FakeVideoClient('portify');
        $who = new PresenceParticipant('m-1', 'u-1', null, [], 'call-ref-1');

        $this->assertSame(['elsewhere' => null, 'superseded' => false], $this->relay()->relay($who, 'join'));
        $this->assertSame('join', $this->video->callPresence[0][2]);
    }
}
