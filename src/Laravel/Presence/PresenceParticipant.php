<?php

namespace Shirahcan\VideoClient\Laravel\Presence;

/**
 * Who is sending presence from a call page, and for which room, as the PRODUCT decided
 * (PresenceAccess). `meetingRef` is the product's own meeting id, for its CallPresenceReceived
 * listeners; `room` is null for a call with no room on the service (nothing is relayed then).
 */
final class PresenceParticipant
{
    public function __construct(
        public readonly string $meetingRef,
        public readonly string $participantId,
        public readonly ?string $room,
        /** Product-only facts its listeners need (Portify: the link's audience). */
        public readonly array $context = [],
    ) {}
}
