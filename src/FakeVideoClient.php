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

    public function repairRoom(string $name): array
    {
        $this->record('repairRoom', compact('name'));
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

    public function token(string $roomName, ?string $participantId, string $displayName, bool $isOwner, ?\DateTimeInterface $expiresAt = null, bool $autoStartTranscription = false, bool $hidden = false): VideoToken
    {
        $this->record('token', compact('roomName', 'participantId', 'displayName', 'isOwner', 'autoStartTranscription', 'hidden'));
        $room = $this->rooms[$roomName] ?? throw new RoomNotFound('No such room for this product.', 'room_not_found', 404);

        $token = 'tok-'.substr(md5($roomName.'|'.$participantId.'|'.(int) $isOwner.'|'.(int) $hidden), 0, 16);

        return new VideoToken($token, "https://{$room->domain}.daily.co/{$roomName}?t={$token}", ($expiresAt ?? new \DateTimeImmutable('+2 hours'))->format(DATE_ATOM));
    }

    public function transcripts(string $roomName): array
    {
        $this->record('transcripts', compact('roomName'));

        return array_values(array_filter($this->transcriptsById, fn (VideoTranscript $t) => $t->room === $roomName));
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
