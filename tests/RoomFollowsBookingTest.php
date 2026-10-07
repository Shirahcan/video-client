<?php

namespace Shirahcan\VideoClient\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\Laravel\RoomFollowsBooking;
use Shirahcan\VideoClient\Laravel\RoomSubject;
use Shirahcan\VideoClient\VideoClient;
use Shirahcan\VideoClient\VideoRoom;

/** A product's meeting, as plain as it gets. */
class FakeMeeting
{
    public ?string $room = null;
    public bool $cancelledNow = false;
    public bool $movedNow = false;

    public function __construct(public string $id, public string $start, public string $end, public bool $video = true) {}
}

class FakeSubject implements RoomSubject
{
    public bool $on = true;

    /** Runs inside remember(), like a product's own save firing the observer again. */
    public ?\Closure $onRemember = null;

    public function enabled(): bool { return $this->on; }

    public function wantsRoom(object $meeting): bool { return $meeting->video; }

    public function externalRef(object $meeting): string { return $meeting->id; }

    public function startsAt(object $meeting): DateTimeInterface { return new DateTimeImmutable($meeting->start); }

    public function endsAt(object $meeting): DateTimeInterface { return new DateTimeImmutable($meeting->end); }

    public function options(object $meeting): array { return ['transcription' => true]; }

    public function roomName(object $meeting): ?string { return $meeting->room; }

    public function remember(object $meeting, VideoRoom $room): void
    {
        $meeting->room = $room->name;
        if ($this->onRemember) {
            ($this->onRemember)($meeting);
        }
    }

    public function justCancelled(object $meeting): bool { return $meeting->cancelledNow; }

    public function justMoved(object $meeting): bool { return $meeting->movedNow; }
}

class RoomFollowsBookingTest extends TestCase
{
    private FakeVideoClient $video;
    private FakeSubject $subject;
    private RoomFollowsBooking $observer;

    protected function setUp(): void
    {
        $this->video = new FakeVideoClient('portify');
        $this->subject = new FakeSubject();
        $video = $this->video;
        $subject = $this->subject;
        $this->observer = new class($video, $subject) extends RoomFollowsBooking
        {
            public function __construct(private VideoClient $v, private RoomSubject $s) {}

            protected function subject(): RoomSubject { return $this->s; }

            protected function client(): VideoClient { return $this->v; }
        };
    }

    private function meeting(): FakeMeeting
    {
        return new FakeMeeting('m-1', '2026-11-02T15:00:00Z', '2026-11-02T15:30:00Z');
    }

    public function test_a_new_meeting_gets_its_room(): void
    {
        $m = $this->meeting();
        $this->observer->created($m);

        $this->assertNotNull($m->room);
        $this->assertSame(1, $this->video->callCount('createRoom'));
    }

    public function test_a_call_with_no_video_gets_none_and_off_does_nothing(): void
    {
        $m = new FakeMeeting('m-2', '2026-11-02T15:00:00Z', '2026-11-02T15:30:00Z', video: false);
        $this->observer->created($m);
        $this->subject->on = false;
        $this->observer->created($this->meeting());

        $this->assertSame(0, $this->video->callCount('createRoom'));
    }

    public function test_a_reschedule_moves_the_same_room(): void
    {
        $m = $this->meeting();
        $this->observer->created($m);
        $name = $m->room;

        $m->start = '2026-11-03T15:00:00Z';
        $m->end = '2026-11-03T15:30:00Z';
        $m->movedNow = true;
        $this->observer->updated($m);

        $this->assertSame($name, $m->room);
        $this->assertSame(1, $this->video->callCount('rescheduleRoom'));
        $this->assertSame(1, $this->video->callCount('createRoom'));
    }

    public function test_a_cancellation_deletes_the_room(): void
    {
        $m = $this->meeting();
        $this->observer->created($m);
        $m->cancelledNow = true;
        $this->observer->updated($m);

        $this->assertSame(1, $this->video->callCount('deleteRoom'));
    }

    public function test_a_meeting_that_missed_its_room_gets_one_on_its_next_save(): void
    {
        $m = $this->meeting();
        $this->observer->updated($m);

        $this->assertNotNull($m->room);
    }

    public function test_a_service_failure_never_breaks_the_save(): void
    {
        $this->video->failNext(new \Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable('down', 'service_unavailable', 503));
        $m = $this->meeting();

        $this->observer->created($m);

        $this->assertNull($m->room);
    }

    public function test_its_own_save_while_keeping_the_room_is_not_a_reschedule(): void
    {
        $observer = $this->observer;
        $this->subject->onRemember = function (FakeMeeting $m) use ($observer) {
            $m->movedNow = true; // a fresh model's times can read as changed
            $observer->updated($m);
        };

        $this->observer->created($this->meeting());

        $this->assertSame(0, $this->video->callCount('rescheduleRoom'));
    }

    public function test_the_room_says_its_internal_address(): void
    {
        $room = new VideoRoom('portify-x', 'm-1', 'shirah', 'active');
        $this->assertSame('https://shirah.daily.co/portify-x', $room->address());
    }
}
