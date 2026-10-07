<?php

namespace Shirahcan\VideoClient\Laravel\Transcripts;

use Shirahcan\VideoClient\Exceptions\VideoServiceException;
use Shirahcan\VideoClient\VideoClient;

/**
 * The transcript sweep and fast path, the same in every product: ask video-service for each
 * recent call's transcripts, fetch the text of any ready one the product does not hold yet, and
 * hand it to the product's TranscriptStore. The service owns the Daily key, parses the WebVTT and
 * keeps none of the text.
 *
 * Best-effort: one room's failure is reported and skipped, never ending the sweep.
 */
final class TranscriptSweep
{
    /** @param \Closure(\Throwable): void $report */
    public function __construct(
        private readonly VideoClient $client,
        private readonly \Closure $report,
    ) {}

    /** @return array{synced: int, skipped: int} */
    public function sweep(TranscriptStore $store): array
    {
        $synced = 0;
        $skipped = 0;

        foreach ($store->roomsToSweep() as $room => $ref) {
            try {
                foreach ($this->client->transcripts((string) $room) as $summary) {
                    if ($store->holdsFinal($summary->id)) {
                        $skipped++;

                        continue;
                    }

                    $transcript = $summary->status === 'ready' ? $this->client->transcript($summary->id) : $summary;
                    $store->store($transcript, $ref !== '' ? (string) $ref : null) ? $synced++ : $skipped++;
                }
            } catch (VideoServiceException $e) {
                ($this->report)($e);
                $skipped++;
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    /** The fast path: one transcript id from a `transcript.ready-to-download` event. */
    public function fetchOne(TranscriptStore $store, string $transcriptId): bool
    {
        if ($transcriptId === '') {
            return false;
        }

        try {
            $transcript = $this->client->transcript($transcriptId);
        } catch (VideoServiceException $e) {
            ($this->report)($e);

            return false;
        }

        return $store->store($transcript, $transcript->externalRef);
    }
}
