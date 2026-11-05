<?php

namespace Shirahcan\VideoClient;

/**
 * A call's LIVE transcription as video-service sees it (GET /v1/rooms/{name}/transcription):
 * whether it is running in the current session, who is in the call, and whether the room still
 * admits people. Render it; a person may start it while someone is in the call and it is not
 * running (VideoClient::startTranscription).
 */
final class VideoLiveTranscription
{
    public function __construct(
        public readonly bool $stores,
        public readonly bool $running,
        public readonly ?string $startedAt,
        public readonly ?string $waitingSince,
        public readonly int $attempts,
        /** @var list<string> participant ids */
        public readonly array $present,
        public readonly bool $open,
    ) {}

    /** Somebody is in the call, it is not being transcribed, and the room is still open. */
    public function canStart(): bool
    {
        return $this->open && ! $this->running && $this->present !== [];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            stores: (bool) ($data['stores'] ?? false),
            running: (bool) ($data['running'] ?? false),
            startedAt: $data['started_at'] ?? null,
            waitingSince: $data['waiting_since'] ?? null,
            attempts: (int) ($data['attempts'] ?? 0),
            present: array_values(array_map('strval', (array) ($data['present'] ?? []))),
            open: (bool) ($data['open'] ?? false),
        );
    }

    /** The shape every product hands its UI (video-embed's LiveTranscription). */
    public function toArray(): array
    {
        return [
            'stores' => $this->stores,
            'running' => $this->running,
            'started_at' => $this->startedAt,
            'waiting_since' => $this->waitingSince,
            'attempts' => $this->attempts,
            'present' => $this->present,
            'open' => $this->open,
            'can_start' => $this->canStart(),
        ];
    }
}
