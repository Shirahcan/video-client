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

    public function repairRoom(string $name, ?\DateTimeInterface $joinableUntil = null, bool $revive = false, bool $openNow = false, ?string $person = null, ?string $linkId = null): array
    {
        $this->record('repairRoom', compact('name', 'joinableUntil', 'revive', 'openNow', 'person', 'linkId'));
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

    /** What verdict() answers by room name; absent = 'not_yet'. @var array<string, VideoVerdict> */
    public array $verdicts = [];

    public function verdict(string $roomName, array $hosts, array $guests): VideoVerdict
    {
        $this->record('verdict', compact('roomName', 'hosts', 'guests'));

        return $this->verdicts[$roomName] ?? new VideoVerdict(VideoVerdict::NOT_YET);
    }

    /** Heartbeats relayed, as [room, participant]. @var array<int, array{0: string, 1: string}> */
    public array $presence = [];

    public function presence(string $roomName, string $participantId, ?\DateTimeInterface $at = null): void
    {
        $this->record('presence', compact('roomName', 'participantId'));
        $this->presence[] = [$roomName, $participantId];
    }

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

    /** Kept transcripts by id (callTranscripts reads these). @var array<string, CallTranscript> */
    public array $callTranscriptsById = [];

    /** Reported join issues, in order. @var array<int, CallJoinIssue> */
    public array $joinIssuesList = [];

    /** Test helper: the service has captured this transcript for a call. */
    public function captured(string $callRef, string $text, array $extra = []): CallTranscript
    {
        $id = (string) (count($this->callTranscriptsById) + 1);

        return $this->callTranscriptsById[$id] = CallTranscript::fromArray($extra + [
            'id' => $id, 'call_ref' => $callRef, 'source' => CallTranscript::CAPTURED, 'status' => 'ready', 'text' => $text,
            'created_at' => date(DATE_ATOM),
        ]);
    }

    public function callTranscripts(string $callRef): array
    {
        $this->record('callTranscripts', compact('callRef'));
        $rows = array_values(array_filter($this->callTranscriptsById, fn (CallTranscript $t) => $t->callRef === $callRef));

        return array_reverse($rows);
    }

    public function supplyTranscript(string $callRef, string $text, ?string $language = null, ?string $suppliedBy = null): CallTranscript
    {
        $this->record('supplyTranscript', compact('callRef', 'text', 'language', 'suppliedBy'));
        if (trim($text) === '') {
            throw new VideoServiceException('The transcript has no text.', 'transcript_empty', 422);
        }
        // A still-failed or unfinished row of the same call is replaced (the service's rule).
        $id = null;
        foreach ($this->callTranscriptsById as $k => $t) {
            if ($t->callRef === $callRef && $t->status !== 'ready') {
                $id = $k;
            }
        }
        $id ??= (string) (count($this->callTranscriptsById) + 1);

        return $this->callTranscriptsById[$id] = CallTranscript::fromArray([
            'id' => $id, 'call_ref' => $callRef, 'source' => CallTranscript::SUPPLIED, 'status' => 'ready', 'text' => trim($text),
            'language' => $language, 'supplied_by' => $suppliedBy, 'supplied_at' => date(DATE_ATOM), 'created_at' => date(DATE_ATOM),
        ]);
    }

    public function importTranscript(string $callRef, array $transcript): CallTranscript
    {
        $this->record('importTranscript', compact('callRef', 'transcript'));
        foreach ($this->callTranscriptsById as $t) {
            if ($t->externalId !== null && $t->externalId === ($transcript['external_id'] ?? null)) {
                return $t;
            }
        }
        $id = (string) (count($this->callTranscriptsById) + 1);

        return $this->callTranscriptsById[$id] = CallTranscript::fromArray(['id' => $id, 'call_ref' => $callRef] + $transcript);
    }

    public function saveCleanText(string $transcriptId, ?string $cleanText): CallTranscript
    {
        $this->record('saveCleanText', compact('transcriptId', 'cleanText'));
        $t = $this->callTranscriptsById[$transcriptId] ?? throw new VideoServiceException('No such transcript for this product.', 'transcript_not_found', 404);

        return $this->callTranscriptsById[$transcriptId] = CallTranscript::fromArray([
            'clean_text' => $cleanText,
            'clean_generated_at' => $cleanText === null ? null : date(DATE_ATOM),
        ] + $t->toArray());
    }

    public function reportJoinIssue(string $callRef, array $issue): CallJoinIssue
    {
        $this->record('reportJoinIssue', compact('callRef', 'issue'));

        return $this->joinIssuesList[] = CallJoinIssue::fromArray($issue + [
            'id' => (string) (count($this->joinIssuesList) + 1), 'call_ref' => $callRef, 'occurred_at' => date(DATE_ATOM),
        ]);
    }

    public function joinIssues(string $callRef): array
    {
        $this->record('joinIssues', compact('callRef'));

        return array_reverse(array_values(array_filter($this->joinIssuesList, fn (CallJoinIssue $i) => $i->callRef === $callRef)));
    }

    /** Every join link ever made, by id (active or not). @var array<string, JoinLink> */
    public array $joinLinksById = [];

    public function joinLinks(string $callRef): array
    {
        $this->record('joinLinks', compact('callRef'));

        return array_values(array_filter($this->joinLinksById, fn (JoinLink $l) => $l->callRef === $callRef && $l->active));
    }

    public function issueJoinLinks(string $callRef, array $audiences): array
    {
        $this->record('issueJoinLinks', compact('callRef', 'audiences'));
        $out = [];
        foreach ($audiences as $a) {
            $active = $this->activeLink($callRef, $a['audience']);
            $out[] = $active ?? $this->mintLink($callRef, $a['audience'], $a['person'] ?? null);
        }

        return $out;
    }

    public function rotateJoinLinks(string $callRef, array $audiences): array
    {
        $this->record('rotateJoinLinks', compact('callRef', 'audiences'));
        $out = [];
        foreach ($audiences as $a) {
            $old = $this->activeLink($callRef, $a['audience']);
            $new = $this->mintLink($callRef, $a['audience'], $a['person'] ?? null);
            if ($old !== null) {
                $this->joinLinksById[$old->id] = JoinLink::fromArray(['active' => false, 'revoked_reason' => 'rescheduled', 'superseded_by' => $new->id, 'revoked_at' => date(DATE_ATOM)] + $old->toArray());
            }
            $out[] = $new;
        }

        return $out;
    }

    public function revokeJoinLinks(string $callRef, string $reason = 'manual'): int
    {
        $this->record('revokeJoinLinks', compact('callRef', 'reason'));
        $n = 0;
        foreach ($this->joinLinksById as $id => $l) {
            if ($l->callRef === $callRef && $l->active) {
                $this->joinLinksById[$id] = JoinLink::fromArray(['active' => false, 'revoked_reason' => $reason, 'revoked_at' => date(DATE_ATOM)] + $l->toArray());
                $n++;
            }
        }

        return $n;
    }

    public function resolveJoinLink(string $token): ?JoinLink
    {
        $this->record('resolveJoinLink', ['token' => '***']);
        foreach ($this->joinLinksById as $l) {
            if (hash_equals($l->token, $token)) {
                return $l;
            }
        }

        return null;
    }

    public function importJoinLink(string $callRef, array $link): JoinLink
    {
        $this->record('importJoinLink', compact('callRef'));
        $held = $this->resolveJoinLink((string) ($link['token'] ?? ''));

        return $held ?? $this->joinLinksById[$id = 'jl-'.(count($this->joinLinksById) + 1)] = JoinLink::fromArray([
            'id' => $id, 'call_ref' => $callRef, 'active' => empty($link['revoked_at']),
        ] + $link);
    }

    public function roomForCall(string $callRef): ?VideoRoom
    {
        $this->record('roomForCall', compact('callRef'));
        foreach ($this->rooms as $room) {
            if ($room->externalRef === $callRef) {
                return $room;
            }
        }

        return null;
    }

    public function rekeyCall(string $fromRef, string $toRef): array
    {
        $this->record('rekeyCall', compact('fromRef', 'toRef'));
        $moved = ['rooms' => 0, 'transcripts' => 0, 'issues' => 0, 'links' => 0];
        foreach ($this->rooms as $name => $room) {
            if ($room->externalRef === $fromRef) {
                $this->rooms[$name] = new VideoRoom($room->name, $toRef, $room->domain, $room->status, $room->startsAt, $room->endsAt, $room->expiresAt, $room->adopted, $room->endedAt);
                $moved['rooms']++;
            }
        }
        foreach ($this->callTranscriptsById as $id => $t) {
            if ($t->callRef === $fromRef) {
                $this->callTranscriptsById[$id] = CallTranscript::fromArray(['call_ref' => $toRef] + $t->toArray());
                $moved['transcripts']++;
            }
        }
        foreach ($this->joinIssuesList as $i => $issue) {
            if ($issue->callRef === $fromRef) {
                $this->joinIssuesList[$i] = CallJoinIssue::fromArray(['call_ref' => $toRef] + $issue->toArray());
                $moved['issues']++;
            }
        }
        foreach ($this->joinLinksById as $id => $l) {
            if ($l->callRef === $fromRef) {
                $this->joinLinksById[$id] = JoinLink::fromArray(['call_ref' => $toRef] + $l->toArray());
                $moved['links']++;
            }
        }

        return $moved;
    }

    private function activeLink(string $callRef, string $audience): ?JoinLink
    {
        foreach ($this->joinLinksById as $l) {
            if ($l->callRef === $callRef && $l->audience === $audience && $l->active) {
                return $l;
            }
        }

        return null;
    }

    private function mintLink(string $callRef, string $audience, ?string $person): JoinLink
    {
        $id = 'jl-'.(count($this->joinLinksById) + 1);

        return $this->joinLinksById[$id] = new JoinLink($id, $callRef, $audience, $person, bin2hex(random_bytes(24)));
    }

    public function usage(?string $month = null): VideoUsageReport
    {
        $this->record('usage', compact('month'));

        return $this->usageReport ?? new VideoUsageReport(['month' => $month ?? date('Y-m'), 'caller' => $this->product, 'estate' => ['minutes' => ['call' => 0, 'transcription' => 0, 'recording' => 0], 'cost_usd' => 0.0], 'products' => []]);
    }

    /** @var array<int, VideoUsageSession> what usageSessions() answers */
    public array $usageSessions = [];

    public function usageSessions(?\DateTimeInterface $since = null, int $limit = 200): array
    {
        $this->record('usageSessions', ['since' => $since?->format(DATE_ATOM), 'limit' => $limit]);

        return array_slice($this->usageSessions, 0, $limit);
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
