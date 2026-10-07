<?php

namespace Shirahcan\VideoClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\Laravel\Transcripts\TranscriptStore;
use Shirahcan\VideoClient\Laravel\Transcripts\TranscriptSweep;
use Shirahcan\VideoClient\VideoTranscript;

class TranscriptSweepTest extends TestCase
{
    private function store(array $rooms, array $final = []): TranscriptStore
    {
        return new class($rooms, $final) implements TranscriptStore
        {
            public array $stored = [];

            public function __construct(private array $rooms, private array $final) {}

            public function roomsToSweep(): iterable
            {
                return $this->rooms;
            }

            public function holdsFinal(string $transcriptId): bool
            {
                return in_array($transcriptId, $this->final, true);
            }

            public function store(VideoTranscript $transcript, ?string $externalRef): bool
            {
                $this->stored[] = [$transcript->id, $transcript->text, $externalRef];

                return true;
            }
        };
    }

    public function test_ready_transcripts_are_fetched_with_text_and_held_ones_are_skipped(): void
    {
        $video = new FakeVideoClient('portify');
        $video->transcriptsById['t1'] = new VideoTranscript('t1', 'ready', 's1', 60, 'room-a', 'm-1', 'Maria: Hi', 1);
        $video->transcriptsById['t2'] = new VideoTranscript('t2', 'ready', 's2', 60, 'room-a', 'm-1', 'Old', 1);
        $video->transcriptsById['t3'] = new VideoTranscript('t3', 'in_progress', 's3', 0, 'room-b', 'm-2');
        $store = $this->store(['room-a' => 'm-1', 'room-b' => 'm-2'], final: ['t2']);

        $result = (new TranscriptSweep($video, fn () => null))->sweep($store);

        $this->assertSame(['synced' => 2, 'skipped' => 1], $result);
        $this->assertSame([['t1', 'Maria: Hi', 'm-1'], ['t3', null, 'm-2']], $store->stored);
    }

    public function test_one_rooms_outage_is_reported_and_the_sweep_goes_on(): void
    {
        $video = new FakeVideoClient('portify');
        $video->transcriptsById['t1'] = new VideoTranscript('t1', 'ready', 's1', 60, 'room-b', 'm-2', 'Hi', 1);
        $video->failNext(new VideoServiceUnavailable('down', 'service_unavailable', 503));
        $reported = [];
        $store = $this->store(['room-a' => 'm-1', 'room-b' => 'm-2']);

        $result = (new TranscriptSweep($video, function ($e) use (&$reported) {
            $reported[] = $e;
        }))->sweep($store);

        $this->assertSame(['synced' => 1, 'skipped' => 1], $result);
        $this->assertCount(1, $reported);
    }

    public function test_the_fast_path_stores_one_transcript_under_its_own_reference(): void
    {
        $video = new FakeVideoClient('portify');
        $video->transcriptsById['t9'] = new VideoTranscript('t9', 'ready', 's9', 60, 'room-z', 'm-9', 'Hello', 1);
        $store = $this->store([]);
        $sweep = new TranscriptSweep($video, fn () => null);

        $this->assertTrue($sweep->fetchOne($store, 't9'));
        $this->assertFalse($sweep->fetchOne($store, 'missing'));
        $this->assertSame([['t9', 'Hello', 'm-9']], $store->stored);
    }
}
