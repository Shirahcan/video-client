<?php

namespace Shirahcan\VideoClient;

/**
 * The call rules' numbers, as video-service publishes them (GET /v1/policy). The same for every
 * product: a product keeps no copy of 30, 60 or 15 (owner 2026-10-06).
 */
final class VideoPolicy
{
    /** @param array<int, int> $extensionSteps */
    public function __construct(
        public readonly int $opensBeforeMinutes,
        public readonly int $closesAfterMinutes,
        public readonly int $expiredAfterCloseMinutes,
        public readonly int $idleEndMinutes,
        public readonly array $extensionSteps,
        public readonly int $extensionMaxExtraMinutes,
        public readonly int $transcriptReadyWithinMinutes,
        /** @var array<int, string> */
        public readonly array $capabilities = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            opensBeforeMinutes: (int) ($data['window']['before_minutes'] ?? 30),
            closesAfterMinutes: (int) ($data['window']['after_minutes'] ?? 30),
            expiredAfterCloseMinutes: (int) ($data['window']['expired_after_close_minutes'] ?? 60),
            idleEndMinutes: (int) ($data['idle_end']['after_minutes'] ?? 15),
            extensionSteps: array_values(array_map('intval', (array) ($data['extension']['steps_minutes'] ?? [15, 30]))),
            extensionMaxExtraMinutes: (int) ($data['extension']['max_extra_minutes'] ?? 120),
            transcriptReadyWithinMinutes: (int) ($data['transcripts']['ready_within_minutes'] ?? 60),
            capabilities: array_values(array_map('strval', (array) ($data['capabilities'] ?? []))),
        );
    }

    public function toArray(): array
    {
        return [
            'window' => [
                'before_minutes' => $this->opensBeforeMinutes,
                'after_minutes' => $this->closesAfterMinutes,
                'expired_after_close_minutes' => $this->expiredAfterCloseMinutes,
            ],
            'idle_end' => ['after_minutes' => $this->idleEndMinutes],
            'extension' => ['steps_minutes' => $this->extensionSteps, 'max_extra_minutes' => $this->extensionMaxExtraMinutes],
            'transcripts' => ['ready_within_minutes' => $this->transcriptReadyWithinMinutes],
            'capabilities' => $this->capabilities,
        ];
    }
}
