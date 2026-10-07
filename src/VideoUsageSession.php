<?php

namespace Shirahcan\VideoClient;

/**
 * One billed call session or transcript of the calling product, attributed by the service's
 * room registry. Minutes only: the free tier is account-wide, so cost lives in the monthly
 * report (VideoClient::usage), never per session.
 */
final class VideoUsageSession
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly ?string $room,
        public readonly ?string $startedAt,
        public readonly int $durationSeconds,
        public readonly int $participantCount,
        public readonly float $billedMinutes,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['kind'] ?? ''),
            (string) ($row['id'] ?? ''),
            isset($row['room']) && $row['room'] !== '' ? (string) $row['room'] : null,
            isset($row['started_at']) ? (string) $row['started_at'] : null,
            (int) ($row['duration_seconds'] ?? 0),
            (int) ($row['participant_count'] ?? 0),
            (float) ($row['billed_minutes'] ?? 0),
        );
    }
}
