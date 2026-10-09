<?php

namespace Shirahcan\VideoClient;

/** One time somebody could not get into a call (or their devices failed), as video-service keeps it. */
final class CallJoinIssue
{
    public function __construct(
        public readonly string $id,
        public readonly string $callRef,
        public readonly string $category,
        public readonly string $kind,
        public readonly ?string $person = null,
        public readonly ?string $linkId = null,
        public readonly ?string $device = null,
        public readonly ?string $outcome = null,
        public readonly ?string $browser = null,
        public readonly ?string $message = null,
        public readonly ?\DateTimeImmutable $occurredAt = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            id: (string) ($d['id'] ?? ''),
            callRef: (string) ($d['call_ref'] ?? ''),
            category: (string) ($d['category'] ?? ''),
            kind: (string) ($d['kind'] ?? ''),
            person: $d['person'] ?? null,
            linkId: $d['link_id'] ?? null,
            device: $d['device'] ?? null,
            outcome: $d['outcome'] ?? null,
            browser: $d['browser'] ?? null,
            message: $d['message'] ?? null,
            occurredAt: is_string($d['occurred_at'] ?? null) ? new \DateTimeImmutable($d['occurred_at']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'call_ref' => $this->callRef, 'category' => $this->category, 'kind' => $this->kind,
            'person' => $this->person, 'link_id' => $this->linkId, 'device' => $this->device, 'outcome' => $this->outcome,
            'browser' => $this->browser, 'message' => $this->message,
            'occurred_at' => $this->occurredAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
