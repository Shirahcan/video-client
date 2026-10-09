<?php

namespace Shirahcan\VideoClient;

/**
 * One transcript of a product's call as video-service KEEPS it (owner 2026-10-09), by the call
 * reference: captured from the call's room, or supplied by a person for a call held elsewhere.
 * `text` is "Speaker: line" plain text (null until it is ready); `cleanText` is what the product's
 * AI tidied, saved back with saveCleanText().
 */
final class CallTranscript
{
    public const CAPTURED = 'captured';

    public const SUPPLIED = 'supplied';

    public function __construct(
        public readonly string $id,
        public readonly string $callRef,
        public readonly string $source,
        public readonly string $status,
        public readonly ?string $text = null,
        public readonly ?string $cleanText = null,
        public readonly ?string $vtt = null,
        public readonly ?string $externalId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $language = null,
        public readonly int $durationSeconds = 0,
        public readonly int $cuesCount = 0,
        public readonly ?string $suppliedBy = null,
        public readonly ?\DateTimeImmutable $suppliedAt = null,
        public readonly ?\DateTimeImmutable $cleanGeneratedAt = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {}

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->text !== null;
    }

    public function isSupplied(): bool
    {
        return $this->source === self::SUPPLIED;
    }

    /** The best text to read: the cleaned one when there is one. */
    public function bestText(): ?string
    {
        return $this->cleanText !== null && trim($this->cleanText) !== '' ? $this->cleanText : $this->text;
    }

    public static function fromArray(array $d): self
    {
        $at = fn ($v) => is_string($v) && $v !== '' ? new \DateTimeImmutable($v) : null;

        return new self(
            id: (string) ($d['id'] ?? ''),
            callRef: (string) ($d['call_ref'] ?? ''),
            source: (string) ($d['source'] ?? self::CAPTURED),
            status: (string) ($d['status'] ?? ''),
            text: $d['text'] ?? null,
            cleanText: $d['clean_text'] ?? null,
            vtt: $d['vtt'] ?? null,
            externalId: $d['external_id'] ?? null,
            sessionId: $d['session_id'] ?? null,
            language: $d['language'] ?? null,
            durationSeconds: (int) ($d['duration_seconds'] ?? 0),
            cuesCount: (int) ($d['cues_count'] ?? 0),
            suppliedBy: $d['supplied_by'] ?? null,
            suppliedAt: $at($d['supplied_at'] ?? null),
            cleanGeneratedAt: $at($d['clean_generated_at'] ?? null),
            createdAt: $at($d['created_at'] ?? null),
        );
    }

    public function toArray(): array
    {
        $iso = fn (?\DateTimeImmutable $d) => $d?->format(\DateTimeInterface::ATOM);

        return [
            'id' => $this->id, 'call_ref' => $this->callRef, 'source' => $this->source, 'status' => $this->status,
            'text' => $this->text, 'clean_text' => $this->cleanText, 'vtt' => $this->vtt,
            'external_id' => $this->externalId, 'session_id' => $this->sessionId, 'language' => $this->language,
            'duration_seconds' => $this->durationSeconds, 'cues_count' => $this->cuesCount,
            'supplied_by' => $this->suppliedBy, 'supplied_at' => $iso($this->suppliedAt),
            'clean_generated_at' => $iso($this->cleanGeneratedAt), 'created_at' => $iso($this->createdAt),
        ];
    }
}
