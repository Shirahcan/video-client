<?php

namespace Shirahcan\VideoClient;

/**
 * Was the call held, as video-service judges it (POST /v1/rooms/{name}/verdict). The product
 * acts on it (completes, marks a no-show) and never re-decides it.
 */
final class VideoVerdict
{
    public const HELD = 'held';

    public const GUEST_ABSENT = 'guest_absent';

    public const HOST_ABSENT = 'host_absent';

    public const NOBODY = 'nobody';

    public const NOT_YET = 'not_yet';

    public const UNKNOWN = 'unknown';

    /** @param array<int, string> $present everyone who came, as participant ids */
    public function __construct(
        public readonly string $verdict,
        public readonly array $present = [],
    ) {}

    /** Settled one way or the other (held or a no-show); otherwise ask again later. */
    public function isFinal(): bool
    {
        return ! in_array($this->verdict, [self::NOT_YET, self::UNKNOWN], true);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['verdict'] ?? self::UNKNOWN),
            array_values(array_map('strval', (array) ($data['present'] ?? []))),
        );
    }
}
