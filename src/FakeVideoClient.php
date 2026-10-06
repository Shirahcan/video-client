<?php

namespace Shirahcan\VideoClient;

use Shirahcan\VideoClient\Exceptions\RoomNotFound;
use Shirahcan\VideoClient\Exceptions\VideoServiceException;

/**
 * In-memory stand-in for tests. Rooms behave like the service's (idempotent create per
 * ref, 404 for an unknown name, no URL without a token); anything can be made to fail
 * with {@see failNext()}. Every call is recorded in {@see $calls}.
 */
class FakeVideoClient implements VideoClient
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    /** @var array<string, VideoRoom> by name */
    public array $rooms = [];

    /** @var array<string, VideoTranscript> by id */
    public array $transcriptsById = [];

    /** @var array<int, array{raw: string, headers: array}> */
    public array $forwarded = [];

    public ?VideoUsageReport $usageReport = null;

    /** @var array<int, VideoServiceException> */
    private array $failures = [];

    public function __construct(private string $product = 'portify', private string $domain = 'shirah') {}

    /** The next call (of any kind) throws this. Queue several to fail several. */
    public function failNext(VideoServiceException $e): static
    {
        $this->failures[] = $e;

        return $this;
    }

    public function createRoom(string $externalRef, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt, array $options = []): VideoRoom
    {
        $this->record('createRoom', compact('externalRef', 'options'));

        foreach ($this->rooms as $room) {
            if ($room->externalRef === $externalRef && $room->status === 'active') {
                return $room;
            }
        }

        $name = $this->product.'-test-'.substr(md5($externalRef), 0, 12);

        return $this->rooms[$name] = new VideoRoom($name, $externalRef, $this->domain, 'active', $startsAt->format(DATE_ATOM), $endsAt->format(DATE_ATOM));
    }

    public function room(string $name): VideoRoom
    {
        $this->record('room', compact('name'));

        return $this->rooms[$name] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);
    }

    public function rescheduleRoom(string $name, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): VideoRoom
    {
        $this->record('rescheduleRoom', compact('name'));
        $room = $this->rooms[$name] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);

        return $this->rooms[$name] = new VideoRoom($name, $room->externalRef, $room->domain, $room->status, $startsAt->format(DATE_ATOM), $endsAt->format(DATE_ATOM));
    }

    public function setExpiry(string $name, \DateTimeInterface $expiresAt): VideoRoom
    {
        $this->record('setExpiry', ['name' => $name, 'expiresAt' => $expiresAt->format(DATE_ATOM)]);
        $room = $this->rooms[$name] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);

        return $this->rooms[$name] = new VideoRoom($name, $room->externalRef, $room->domain, $room->status, $room->startsAt, $room->endsAt, $expiresAt->format(DATE_ATOM), $room->adopted);
    }

    public function repairRoom(string $name, ?\DateTimeInterface $joinableUntil = null, bool $revive = false, bool $openNow = false): array
    {
        $this->record('repairRoom', compact('name', 'joinableUntil', 'revive', 'openNow'));
        $room = $this->rooms[$name] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);

        return ['room' => $room, 'issues' => [], 'actions' => []];
    }

    public function deleteRoom(string $name): void
    {
        $this->record('deleteRoom', compact('name'));
        if (isset($this->rooms[$name])) {
            $r = $this->rooms[$name];
            $this->rooms[$name] = new VideoRoom($name, $r->externalRef, $r->domain, 'deleted', $r->startsAt, $r->endsAt);
        }
    }

    public function adoptRoom(string $name, string $externalRef, array $window = []): VideoRoom
    {
        $this->record('adoptRoom', compact('name', 'externalRef'));

        return $this->rooms[$name] ??= new VideoRoom($name, $externalRef, $window['domain'] ?? $this->domain, 'active', $window['starts_at'] ?? null, $window['ends_at'] ?? null, null, true);
    }

    public function endRoom(string $name, ?string $endedBy = null): VideoRoom
    {
        $this->record('endRoom', compact('name', 'endedBy'));
        $r = $this->rooms[$name] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);
        if ($r->isEnded()) {
            return $r;
        }

        return $this->rooms[$name] = new VideoRoom($name, $r->externalRef, $r->domain, $r->status, $r->startsAt, $r->endsAt, $r->expiresAt, $r->adopted, date(DATE_ATOM));
    }

    /** What endIfIdle() answers by room name; absent = not idle ('recently_left'). @var array<string, bool> */
    public array $idleRooms = [];

    public function endIfIdle(string $name, ?int $idleMinutes = null): array
    {
        $this->record('endIfIdle', compact('name', 'idleMinutes'));
        $r = $this->rooms[$name] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);
        if ($r->isEnded()) {
            return ['ended' => true, 'reason' => 'already_ended'];
        }
        if (! ($this->idleRooms[$name] ?? false)) {
            return ['ended' => false, 'reason' => 'recently_left'];
        }
        $this->endRoom($name, 'idle');

        return ['ended' => true, 'reason' => 'idle'];
    }

    public function token(string $roomName, ?string $participantId, string $displayName, bool $isOwner, ?\DateTimeInterface $expiresAt = null, bool $autoStartTranscription = false, bool $hidden = false): VideoToken
    {
        $this->record('token', compact('roomName', 'participantId', 'displayName', 'isOwner', 'expiresAt', 'autoStartTranscription', 'hidden'));
        $room = $this->rooms[$roomName] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);
        if ($room->isEnded()) {
            throw new Exceptions\VideoRequestRejected('The host has ended this call.', 'room_ended', 409);
        }

        $token = 'tok-'.substr(md5($roomName.'|'.$participantId.'|'.(int) $isOwner.'|'.(int) $hidden), 0, 16);

        return new VideoToken($token, "https://{$room->domain}.daily.co/{$roomName}?t={$token}", ($expiresAt ?? new \DateTimeImmutable('+2 hours'))->format(DATE_ATOM));
    }

    public function state(string $roomName): VideoCallState
    {
        $this->record('state', compact('roomName'));
        $room = $this->rooms[$roomName] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);

        return new VideoCallState(
            $room->status === 'deleted' ? 'cancelled' : ($room->isEnded() ? 'closed' : 'open'),
            null, $room->startsAt, $room->endsAt, $room->expiresAt, date(DATE_ATOM), false,
        );
    }

    /**
     * What attendance() answers, by room name: the participant ids that joined. A room
     * that exists but is absent here was empty.
     *
     * @var array<string, array<int, string>>
     */
    public array $attendanceByRoom = [];

    /**
     * When the last person left, by room name (attendance()'s `lastLeftAt`); a room listed
     * in $ongoingRooms reads as still in a call.
     *
     * @var array<string, string>
     */
    public array $lastLeftByRoom = [];

    /** @var array<int, string> */
    public array $ongoingRooms = [];

    public function attendance(string $roomName): VideoAttendance
    {
        $this->record('attendance', compact('roomName'));
        if (! isset($this->rooms[$roomName])) {
            throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);
        }

        $ids = $this->attendanceByRoom[$roomName] ?? [];

        $ongoing = in_array($roomName, $this->ongoingRooms, true);

        return new VideoAttendance($roomName, $ids === [] ? 0 : 1, $ongoing, 0, array_fill_keys(
            $ids,
            ['first_joined_at' => date(DATE_ATOM), 'seconds' => 600],
        ), $ongoing ? null : ($this->lastLeftByRoom[$roomName] ?? null));
    }

    public function transcripts(string $roomName): array
    {
        $this->record('transcripts', compact('roomName'));

        return array_values(array_filter($this->transcriptsById, fn (VideoTranscript $t) => $t->room === $roomName));
    }

    /** What transcriptStatus() answers by room name; absent = not_started. @var array<string, VideoTranscriptStatus> */
    public array $transcriptStatusByRoom = [];

    public function transcriptStatus(string $roomName): VideoTranscriptStatus
    {
        $this->record('transcriptStatus', compact('roomName'));
        if (! isset($this->rooms[$roomName])) {
            throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);
        }

        return $this->transcriptStatusByRoom[$roomName]
            ?? new VideoTranscriptStatus(VideoTranscriptStatus::NOT_STARTED, true, null, null, [], 60);
    }

    public function transcript(string $transcriptId): VideoTranscript
    {
        $this->record('transcript', compact('transcriptId'));

        return $this->transcriptsById[$transcriptId] ?? throw new RoomNotFound('No such transcript for this product.', 'transcript_not_found', 404);
    }

    public function usage(?string $month = null): VideoUsageReport
    {
        $this->record('usage', compact('month'));

        return $this->usageReport ?? new VideoUsageReport(['month' => $month ?? date('Y-m'), 'caller' => $this->product, 'estate' => ['minutes' => ['call' => 0, 'transcription' => 0, 'recording' => 0], 'cost_usd' => 0.0], 'products' => []]);
    }

    /** What policy() answers; null = the service's defaults. */
    public ?VideoPolicy $policyAnswer = null;

    public function policy(): VideoPolicy
    {
        $this->record('policy', []);

        return $this->policyAnswer ?? VideoPolicy::fromArray([]);
    }

    public function roomHealth(): array
    {
        $this->record('roomHealth', []);

        return ['audited' => count($this->rooms), 'findings' => []];
    }

    public function forwardDailyWebhook(string $rawBody, array $headers): array
    {
        $this->record('forwardDailyWebhook', []);
        $this->forwarded[] = ['raw' => $rawBody, 'headers' => $headers];

        return ['event_id' => md5($rawBody), 'duplicate' => false];
    }

    /** How many times a method was called. */
    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }
    }
}
