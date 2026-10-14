<?php

namespace Shirahcan\VideoClient\Laravel\Presence;

use Shirahcan\VideoClient\VideoCallSummary;
use Shirahcan\VideoClient\VideoClient;

/**
 * Whether a call is live, who is in it now, who ever came, and when it started and ended, as
 * video-service saw it, by the call's ref. A product keeps no presence log and no start or end
 * stamps of its own; every reader asks here.
 *
 * Request-scoped (bound `scoped`) and batched: `preload()` asks the service once for a whole list
 * of refs, and each summary is kept for the request. A call the service cannot speak for (an
 * outage) reads as unknown (null), never as a guess: there is no copy to fall back on.
 */
class CallLive
{
    /** @var array<string, VideoCallSummary|null> by call ref (null = the service could not say) */
    private array $summaries = [];

    /** @param callable(\Throwable): void $report */
    public function __construct(
        private readonly VideoClient $client,
        private $report,
    ) {}

    /** @param iterable<string|null> $refs */
    public function preload(iterable $refs): void
    {
        $ask = [];
        foreach ($refs as $ref) {
            if (is_string($ref) && $ref !== '' && ! array_key_exists($ref, $this->summaries)) {
                $ask[$ref] = true;
            }
        }
        if ($ask === []) {
            return;
        }

        try {
            foreach ($this->client->callSummaries(array_keys($ask)) as $ref => $summary) {
                $this->summaries[(string) $ref] = $summary;
            }
        } catch (\Throwable $e) {
            ($this->report)($e);
            foreach (array_keys($ask) as $ref) {
                $this->summaries[$ref] = null;
            }
        }
    }

    public function summary(?string $ref): ?VideoCallSummary
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        if (! array_key_exists($ref, $this->summaries)) {
            $this->preload([$ref]);
        }

        return $this->summaries[$ref] ?? null;
    }

    /** Someone is in the call now. */
    public function isLive(?string $ref): bool
    {
        return (bool) $this->summary($ref)?->live;
    }

    /** @return array<int, string> who is in the call now */
    public function inNow(?string $ref): array
    {
        return $this->summary($ref)?->inNow ?? [];
    }

    /** @return array<int, string> everyone who ever came into the call */
    public function seen(?string $ref): array
    {
        return $this->summary($ref)?->present ?? [];
    }

    /** When the first person joined; null before anyone has (or when unknown). */
    public function startedAt(?string $ref): ?\DateTimeImmutable
    {
        $at = $this->summary($ref)?->firstJoinedAt;

        return $at !== null ? new \DateTimeImmutable($at) : null;
    }

    /** When it ended: the room's end, else the last time anyone was seen once nobody is in. */
    public function endedAt(?string $ref): ?\DateTimeImmutable
    {
        $s = $this->summary($ref);
        if ($s === null) {
            return null;
        }
        if ($s->endedAt !== null) {
            return new \DateTimeImmutable($s->endedAt);
        }

        return ! $s->live && $s->lastSeenAt !== null ? new \DateTimeImmutable($s->lastSeenAt) : null;
    }

    /** After a change the service saw (a join, a leave): ask again next time. */
    public function forget(?string $ref): void
    {
        if ($ref !== null) {
            unset($this->summaries[$ref]);
        }
    }
}
