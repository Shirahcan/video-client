<?php

namespace Shirahcan\VideoClient;

/**
 * Who joined a room, from Daily's own session record. Participants are keyed on the
 * participant id the product put in the token, so "did the client come" is a lookup.
 *
 * A Daily outage throws (VideoServiceUnavailable); it never comes back as an empty room.
 */
final class VideoAttendance
{
    public function __construct(
        public readonly string $room,
        public readonly int $sessions,
        public readonly bool $ongoing,
        public readonly int $anonymousSeconds,
        /** @var array<string, array{first_joined_at: string, seconds: int}> by participant id */
        public readonly array $participants,
        /** When the last person left (ISO 8601); null while the call is on or nobody came. */
        public readonly ?string $lastLeftAt = null,
    ) {}

    public function attended(string $participantId): bool
    {
        return isset($this->participants[$participantId]);
    }

    /** True when nobody, identified or not, ever entered the room. */
    public function isEmpty(): bool
    {
        return $this->participants === [] && $this->anonymousSeconds === 0;
    }

    public static function fromArray(array $data): self
    {
        $participants = [];
        foreach ((array) ($data['participants'] ?? []) as $p) {
            $id = (string) ($p['participant_id'] ?? '');
            if ($id !== '') {
                $participants[$id] = [
                    'first_joined_at' => (string) ($p['first_joined_at'] ?? ''),
                    'seconds' => (int) ($p['seconds'] ?? 0),
                ];
            }
        }

        return new self(
            room: (string) ($data['room'] ?? ''),
            sessions: (int) ($data['sessions'] ?? 0),
            ongoing: (bool) ($data['ongoing'] ?? false),
            anonymousSeconds: (int) ($data['anonymous_seconds'] ?? 0),
            participants: $participants,
            lastLeftAt: $data['last_left_at'] ?? null,
        );
    }
}
