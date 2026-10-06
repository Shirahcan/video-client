<?php

namespace Shirahcan\VideoClient;

/**
 * Where a call's transcript is, as video-service judges it from Daily's own records. Render
 * it; never recompute it (a product that guessed told people "no transcript" while Daily was
 * still writing one).
 */
final class VideoTranscriptStatus
{
    public const NOT_TRANSCRIBED = 'not_transcribed';

    public const NOT_STARTED = 'not_started';

    public const NO_CALL = 'no_call';

    public const IN_CALL = 'in_call';

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const OVERDUE = 'overdue';

    public function __construct(
        public readonly string $state,
        public readonly bool $transcribing,
        public readonly ?string $callEndedAt,
        public readonly ?string $expectedBy,
        /** @var array<int, array{id: string, status: string}> */
        public readonly array $transcripts,
        public readonly int $readyWithinMinutes,
    ) {}

    /** The ids of transcripts Daily has finished. */
    public function readyIds(): array
    {
        return array_values(array_map(
            fn (array $t) => (string) $t['id'],
            array_filter($this->transcripts, fn (array $t) => ($t['status'] ?? '') === 'ready'),
        ));
    }

    public static function fromArray(array $data): self
    {
        return new self(
            state: (string) ($data['state'] ?? self::NOT_STARTED),
            transcribing: (bool) ($data['transcribing'] ?? false),
            callEndedAt: $data['call_ended_at'] ?? null,
            expectedBy: $data['expected_by'] ?? null,
            transcripts: array_values(array_map(
                fn ($t) => ['id' => (string) ($t['id'] ?? ''), 'status' => (string) ($t['status'] ?? '')],
                array_filter((array) ($data['transcripts'] ?? []), 'is_array'),
            )),
            readyWithinMinutes: (int) ($data['ready_within_minutes'] ?? 60),
        );
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'transcribing' => $this->transcribing,
            'call_ended_at' => $this->callEndedAt,
            'expected_by' => $this->expectedBy,
            'ready_within_minutes' => $this->readyWithinMinutes,
        ];
    }
}
