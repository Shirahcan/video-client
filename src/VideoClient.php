<?php

namespace Shirahcan\VideoClient;

/**
 * What a product may ask video-service. Type-hint THIS, so tests can bind
 * {@see FakeVideoClient} without touching a socket.
 */
interface VideoClient
{
    /** Create (or get back) the room for one of this product's things. Idempotent per ref. */
    public function createRoom(string $externalRef, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt, array $options = []): VideoRoom;

    public function room(string $name): VideoRoom;

    public function rescheduleRoom(string $name, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): VideoRoom;

    /** Set the room's expiry to an exact moment (a host's extension, or closing it now). */
    public function setExpiry(string $name, \DateTimeInterface $expiresAt): VideoRoom;

    /**
     * Heal one room before (or while) someone enters it: rebuild under the same name if Daily
     * lost it, move an exp that ends too early, restore knocking. `$joinableUntil` is the
     * product's own close (host extension included) so a repair never shortens the call;
     * `$revive` rebuilds a room deleted by a cancel the product has undone. Any change drops
     * the room's cached tokens, so the next token() mints afresh. `$openNow` is the product's
     * word that someone should be able to enter NOW (a room Daily still holds shut is opened).
     *
     * @return array{room: VideoRoom, issues: array<int, string>, actions: array<int, string>}
     */
    public function repairRoom(string $name, ?\DateTimeInterface $joinableUntil = null, bool $revive = false, bool $openNow = false): array;

    /**
     * The host ENDED the call: everyone in it is removed now and the room admits nobody again
     * (no token, no repair). Idempotent. `$endedBy` is the product's id for who ended it.
     */
    public function endRoom(string $name, ?string $endedBy = null): VideoRoom;

    /**
     * The shared idle rule: end a HELD call (2+ identified people joined) that everyone left
     * at least `$idleMinutes` ago. The product decides when to ask.
     *
     * @return array{ended: bool, reason: string}
     */
    public function endIfIdle(string $name, int $idleMinutes): array;

    /** Idempotent: deleting an already-deleted room succeeds. */
    public function deleteRoom(string $name): void;

    /** Register a room that already exists on Daily (cutover backfill). */
    public function adoptRoom(string $name, string $externalRef, array $window = []): VideoRoom;

    /** The ONLY way to get a joinable URL: always carries its token. */
    public function token(string $roomName, ?string $participantId, string $displayName, bool $isOwner, ?\DateTimeInterface $expiresAt = null, bool $autoStartTranscription = false, bool $hidden = false): VideoToken;

    /** The call-state contract (V16). No Daily call behind it: safe to poll. */
    public function state(string $roomName): VideoCallState;

    /** Who joined, from Daily's session record. Throws when Daily cannot answer. */
    public function attendance(string $roomName): VideoAttendance;

    /** @return array<int, VideoTranscript> */
    public function transcripts(string $roomName): array;

    public function transcript(string $transcriptId): VideoTranscript;

    public function usage(?string $month = null): VideoUsageReport;

    /** @return array{audited: int, findings: array<int, array>} */
    public function roomHealth(): array;

    /** Portify only: forward one raw Daily webhook delivery (the D1 pipe). */
    public function forwardDailyWebhook(string $rawBody, array $headers): array;
}
