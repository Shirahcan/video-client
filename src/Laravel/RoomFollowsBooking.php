<?php

namespace Shirahcan\VideoClient\Laravel;

use Shirahcan\VideoClient\Exceptions\VideoServiceException;
use Shirahcan\VideoClient\VideoClient;

/**
 * A meeting's video room follows the meeting, the same way in every product (plan
 * shared-meetings P04): made when the meeting is created, moved when it is rescheduled (the
 * SAME room, so every link already sent keeps working), deleted when it is cancelled. Before
 * this, each product wired its own: Portify made rooms in two booking paths only and
 * re-created instead of moving.
 *
 * A model observer the product extends with its RoomSubject:
 *
 *     class MeetingRoomObserver extends RoomFollowsBooking {
 *         protected function subject(): RoomSubject { return app(PortifyRoomSubject::class); }
 *     }
 *     Meeting::observe(MeetingRoomObserver::class);
 *
 * ⚠ NEVER BREAKS THE SAVE. A booking the person was told succeeded stands; a room that could
 * not be made is reported, and the service's own pre-flight check and the join path repair it.
 */
abstract class RoomFollowsBooking
{
    abstract protected function subject(): RoomSubject;

    protected function client(): VideoClient
    {
        return app(VideoClient::class);
    }

    public function created(object $meeting): void
    {
        $subject = $this->subject();
        if (! $subject->enabled() || ! $subject->wantsRoom($meeting)) {
            return;
        }

        $this->quietly(fn () => $this->ensure($subject, $meeting));
    }

    public function updated(object $meeting): void
    {
        $subject = $this->subject();
        if (! $subject->enabled()) {
            return;
        }

        $room = $subject->roomName($meeting);

        if ($subject->justCancelled($meeting)) {
            if ($room !== null) {
                $this->quietly(fn () => $this->client()->deleteRoom($room));
            }

            return;
        }

        if (! $subject->wantsRoom($meeting)) {
            return;
        }

        if ($room === null) {
            $this->quietly(fn () => $this->ensure($subject, $meeting));

            return;
        }

        if ($subject->justMoved($meeting)) {
            $this->quietly(fn () => $subject->remember(
                $meeting,
                $this->client()->rescheduleRoom($room, $subject->startsAt($meeting), $subject->endsAt($meeting)),
            ));
        }
    }

    private function ensure(RoomSubject $subject, object $meeting): void
    {
        // Keyed on the meeting: asking twice returns the same room, never a second one.
        $subject->remember($meeting, $this->client()->createRoom(
            $subject->externalRef($meeting),
            $subject->startsAt($meeting),
            $subject->endsAt($meeting),
            $subject->options($meeting),
        ));
    }

    private function quietly(callable $fn): void
    {
        try {
            $fn();
        } catch (VideoServiceException $e) {
            if (function_exists('report')) {
                report($e);
            }
        }
    }
}
