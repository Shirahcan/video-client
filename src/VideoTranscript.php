<?php

namespace Shirahcan\VideoClient;

/**
 * One transcript. `text` is "Speaker: line" plain text, or null until Daily finishes.
 * The service does not keep it (D2): store what you need.
 */
final class VideoTranscript
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $sessionId,
        public readonly int $durationSeconds,
        public readonly ?string $room = null,
        public readonly ?string $externalRef = null,
        public readonly ?string $text = null,
        public readonly int $cuesCount = 0,
    ) {}

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->text !== null;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            sessionId: $data['session_id'] ?? null,
            durationSeconds: (int) ($data['duration_seconds'] ?? 0),
            room: $data['room'] ?? null,
            externalRef: $data['external_ref'] ?? null,
            text: $data['text'] ?? null,
            cuesCount: (int) ($data['cues_count'] ?? 0),
        );
    }
}
