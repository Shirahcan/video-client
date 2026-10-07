<?php

namespace Shirahcan\VideoClient\Laravel\Presence;

/**
 * Raised after the kit relayed a presence signal to video-service, for the PRODUCT's own
 * follow-ups (Portify starts the consultation session on the first one). Deciding who came is
 * never done here: that is the service's verdict.
 */
final class CallPresenceReceived
{
    /** @param 'join'|'heartbeat'|'leave' $kind */
    public function __construct(
        public readonly PresenceParticipant $participant,
        public readonly string $kind,
        public readonly \DateTimeImmutable $at,
    ) {}
}
