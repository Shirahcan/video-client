<?php

namespace Shirahcan\VideoClient;

/**
 * Someone asking to join a call by its "anyone with the link" link, as video-service keeps it
 * (plan M7j): `waiting` until the host answers (`admitted` or `declined`), `expired` when nobody
 * answered in time.
 */
final class JoinRequest
{
    public const WAITING = 'waiting';

    public const ADMITTED = 'admitted';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public function __construct(
        public readonly string $id,
        public readonly string $callRef,
        public readonly string $name,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $decidedAt = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            id: (string) ($d['id'] ?? ''),
            callRef: (string) ($d['call_ref'] ?? ''),
            name: (string) ($d['name'] ?? ''),
            status: (string) ($d['status'] ?? self::WAITING),
            createdAt: is_string($d['created_at'] ?? null) ? new \DateTimeImmutable($d['created_at']) : null,
            decidedAt: is_string($d['decided_at'] ?? null) ? new \DateTimeImmutable($d['decided_at']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'call_ref' => $this->callRef, 'name' => $this->name, 'status' => $this->status,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'decided_at' => $this->decidedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
