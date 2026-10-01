<?php

namespace Shirahcan\VideoClient;

/**
 * A Daily event the service pushed to this product (V7), already verified and routed:
 * it belongs to one of THIS product's rooms, named by the product's own external_ref.
 */
final class VideoEvent
{
    public function __construct(
        public readonly string $event,
        public readonly string $eventId,
        public readonly string $room,
        public readonly string $externalRef,
        public readonly ?string $occurredAt,
        public readonly array $payload,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            event: (string) ($data['event'] ?? ''),
            eventId: (string) ($data['event_id'] ?? ''),
            room: (string) ($data['room'] ?? ''),
            externalRef: (string) ($data['external_ref'] ?? ''),
            occurredAt: $data['occurred_at'] ?? null,
            payload: (array) ($data['payload'] ?? []),
        );
    }
}
