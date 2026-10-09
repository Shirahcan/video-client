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
    public function repairRoom(string $name, ?\DateTimeInterface $joinableUntil = null, bool $revive = false, bool $openNow = false, ?string $person = null, ?string $linkId = null): array;

    /**
     * The host ENDED the call: everyone in it is removed now and the room admits nobody again
     * (no token, no repair). Idempotent. `$endedBy` is the product's id for who ended it.
     */
    public function endRoom(string $name, ?string $endedBy = null): VideoRoom;

    /**
     * The shared idle rule: end a HELD call (2+ identified people joined) that everyone left
     * at least `$idleMinutes` ago. Null = the service's own number (the same for every
     * product, config video-service.idle_end). The product decides when to ask.
     *
     * @return array{ended: bool, reason: string}
     */
    public function endIfIdle(string $name, ?int $idleMinutes = null): array;

    /** Where this call's transcript is (not transcribed, in the call, preparing, ready, overdue). */
    public function transcriptStatus(string $roomName): VideoTranscriptStatus;

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

    /**
     * Was the call held: the shared rule, judged from the SERVICE'S own evidence (Daily's
     * record, Daily's participant webhooks, relayed heartbeats). The product only says who is
     * on which side.
     *
     * @param  array<int, string>  $hosts
     * @param  array<int, string>  $guests
     */
    public function verdict(string $roomName, array $hosts, array $guests): VideoVerdict;

    /**
     * Relay one in-call heartbeat from the product's call page (the browser cannot reach the
     * service). The service keeps it as its own witness for the verdict.
     */
    public function presence(string $roomName, string $participantId, ?\DateTimeInterface $at = null): void;

    /** @return array<int, VideoTranscript> */
    public function transcripts(string $roomName): array;

    public function transcript(string $transcriptId): VideoTranscript;

    /**
     * A call's transcripts as the service KEEPS them (owner 2026-10-09), newest first. The call
     * reference is the product's booking id, or its own reference for a call never booked.
     *
     * @return array<int, CallTranscript>
     */
    public function callTranscripts(string $callRef): array;

    /** A person's transcript for a call (held elsewhere, or whose capture failed). */
    public function supplyTranscript(string $callRef, string $text, ?string $language = null, ?string $suppliedBy = null): CallTranscript;

    /**
     * Move a transcript the product already held into the service, as it was. `$transcript`:
     * external_id (required, the idempotency key), source, status, and optionally session_id,
     * language, duration_seconds, text, vtt, clean_text, clean_generated_at, supplied_by,
     * supplied_at, created_at.
     */
    public function importTranscript(string $callRef, array $transcript): CallTranscript;

    /** Save (or clear, with null) the product's cleaned text on a kept transcript. */
    public function saveCleanText(string $transcriptId, ?string $cleanText): CallTranscript;

    /**
     * Report that somebody could not get into a call. `$issue`: category (call|device), kind,
     * and optionally device, outcome, browser, message, person, link_id, occurred_at.
     */
    public function reportJoinIssue(string $callRef, array $issue): CallJoinIssue;

    /** @return array<int, CallJoinIssue> newest first */
    public function joinIssues(string $callRef): array;

    public function usage(?string $month = null): VideoUsageReport;

    /**
     * The calling product's own billed sessions and transcripts since a moment, newest first.
     *
     * @return array<int, VideoUsageSession>
     */
    public function usageSessions(?\DateTimeInterface $since = null, int $limit = 200): array;

    /** The call rules' numbers (window, idle end, extension, transcript wait), shared by every product. */
    public function policy(): VideoPolicy;

    /** @return array{audited: int, findings: array<int, array>} */
    public function roomHealth(): array;

    /** Portify only: forward one raw Daily webhook delivery (the D1 pipe). */
    public function forwardDailyWebhook(string $rawBody, array $headers): array;
}
