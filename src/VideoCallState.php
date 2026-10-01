<?php

namespace Shirahcan\VideoClient;

/**
 * The call-state contract (plan V16): the ONE answer to "can this call be joined, and until
 * when", identical for every product. Render it; never recompute it. Count down from
 * `serverTime`, not the browser's clock.
 */
final class VideoCallState
{
    public function __construct(
        /** not_open | open | grace | closed | cancelled | stranded */
        public readonly string $state,
        public readonly ?string $opensAt,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $closesAt,
        public readonly string $serverTime,
        public readonly bool $transcribing,
        /** @var array<int, string> */
        public readonly array $capabilities = [],
    ) {}

    public function isJoinable(): bool
    {
        return in_array($this->state, ['open', 'grace'], true);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            state: (string) ($data['state'] ?? 'closed'),
            opensAt: $data['opens_at'] ?? null,
            startsAt: $data['starts_at'] ?? null,
            endsAt: $data['ends_at'] ?? null,
            closesAt: $data['closes_at'] ?? null,
            serverTime: (string) ($data['server_time'] ?? ''),
            transcribing: (bool) ($data['transcribing'] ?? false),
            capabilities: array_values((array) ($data['capabilities'] ?? [])),
        );
    }

    /** The wire shape, for a product to pass through to its frontend unchanged. */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'opens_at' => $this->opensAt,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'closes_at' => $this->closesAt,
            'server_time' => $this->serverTime,
            'transcribing' => $this->transcribing,
            'capabilities' => $this->capabilities,
        ];
    }
}
