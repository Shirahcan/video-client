<?php

namespace Shirahcan\VideoClient\Laravel\Transcripts;

use Shirahcan\VideoClient\VideoTranscript;

/**
 * The product's side of the transcript sweep (TranscriptSweep): which calls may still owe a
 * transcript, which transcripts it already holds as final, and where a fetched one goes. Its
 * storage, the ready signal and whatever follows (Portify's Porter recap) stay the product's.
 */
interface TranscriptStore
{
    /**
     * Rooms of recent calls that may still owe a transcript, keyed by room name, valued by the
     * product's own reference for the call (its external_ref).
     *
     * @return iterable<string, string>
     */
    public function roomsToSweep(): iterable;

    /** True when this transcript is already held as final (nothing to fetch). */
    public function holdsFinal(string $transcriptId): bool;

    /** Store one transcript (final or in progress). True when it was written. */
    public function store(VideoTranscript $transcript, ?string $externalRef): bool;
}
