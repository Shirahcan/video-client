<?php

namespace Shirahcan\VideoClient;

/** One person's join link to a product's call, as video-service keeps it (plan C1a). */
final class JoinLink
{
    public function __construct(
        public readonly string $id,
        public readonly string $callRef,
        public readonly string $audience,
        public readonly ?string $person,
        public readonly string $token,
        public readonly bool $active = true,
        public readonly ?string $revokedReason = null,
        public readonly ?string $supersededBy = null,
        public readonly ?\DateTimeImmutable $revokedAt = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            id: (string) ($d['id'] ?? ''),
            callRef: (string) ($d['call_ref'] ?? ''),
            audience: (string) ($d['audience'] ?? ''),
            person: $d['person'] ?? null,
            token: (string) ($d['token'] ?? ''),
            active: (bool) ($d['active'] ?? true),
            revokedReason: $d['revoked_reason'] ?? null,
            supersededBy: $d['superseded_by'] ?? null,
            revokedAt: is_string($d['revoked_at'] ?? null) ? new \DateTimeImmutable($d['revoked_at']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'call_ref' => $this->callRef, 'audience' => $this->audience, 'person' => $this->person,
            'token' => $this->token, 'active' => $this->active, 'revoked_reason' => $this->revokedReason,
            'superseded_by' => $this->supersededBy, 'revoked_at' => $this->revokedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
