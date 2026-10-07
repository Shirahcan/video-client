<?php

namespace Shirahcan\VideoClient\Laravel;

use DateTimeInterface;
use Shirahcan\VideoClient\VideoRoom;

/**
 * What a product's meeting means to its video room, so RoomFollowsBooking can keep the two in
 * step the same way in every product. The product answers these from its own model; the kit
 * never learns what a "meeting" is.
 */
interface RoomSubject
{
    /** The product's switch for this behaviour (and that the service is reachable at all). */
    public function enabled(): bool;

    /** Should this meeting have a video room (a call on the service's video, not cancelled)? */
    public function wantsRoom(object $meeting): bool;

    /** The meeting's id as the service keys the room (`external_ref`). */
    public function externalRef(object $meeting): string;

    public function startsAt(object $meeting): DateTimeInterface;

    public function endsAt(object $meeting): DateTimeInterface;

    /** createRoom() options: transcription, max_participants, knocking. */
    public function options(object $meeting): array;

    /** The room name the product holds for this meeting, or null when it has none yet. */
    public function roomName(object $meeting): ?string;

    /** Keep the room on the product's meeting. Must save WITHOUT firing model events again. */
    public function remember(object $meeting, VideoRoom $room): void;

    /** This save cancelled the meeting (it was not cancelled before). */
    public function justCancelled(object $meeting): bool;

    /** This save moved the meeting's start or end. */
    public function justMoved(object $meeting): bool;
}
