<?php

namespace Shirahcan\VideoClient;

/**
 * What video-service saw of a call, by its ref (POST /v1/calls/summaries): when the first person
 * joined, when anyone was last seen, when it ended, whether it is live now, and who was seen. The
 * product keeps no presence log and no start or end stamps; it reads these.
 */
final class VideoCallSummary
{
    /** @param array<int, string> $present */
    public function __construct(
        public readonly string $ref,
        public readonly ?string $room,
        public readonly ?string $firstJoinedAt,
        public readonly ?string $lastSeenAt,
        public readonly ?string $endedAt,
        public readonly bool $live,
        public readonly array $present = [],
    ) {}

    public static function fromArray(string $ref, array $row): self
    {
        return new self(
            $ref,
            isset($row['room']) && $row['room'] !== '' ? (string) $row['room'] : null,
            $row['first_joined_at'] ?? null,
            $row['last_seen_at'] ?? null,
            $row['ended_at'] ?? null,
            (bool) ($row['live'] ?? false),
            array_values(array_map('strval', (array) ($row['present'] ?? []))),
        );
    }

    /** Nobody has been seen: the call has not started. */
    public static function none(string $ref): self
    {
        return new self($ref, null, null, null, null, false);
    }
}
