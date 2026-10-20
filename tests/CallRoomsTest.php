<?php

namespace Shirahcan\VideoClient\Tests;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\Laravel\Rooms\CallRooms;

class CallRoomsTest extends TestCase
{
    private function cache(): CacheInterface
    {
        return new class implements CacheInterface
        {
            public array $items = [];

            public function get(string $key, mixed $default = null): mixed { return $this->items[$key] ?? $default; }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool { $this->items[$key] = $value; return true; }

            public function delete(string $key): bool { unset($this->items[$key]); return true; }

            public function clear(): bool { $this->items = []; return true; }

            public function getMultiple(iterable $keys, mixed $default = null): iterable { return []; }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool { return true; }

            public function deleteMultiple(iterable $keys): bool { return true; }

            public function has(string $key): bool { return isset($this->items[$key]); }
        };
    }

    public function test_a_calls_room_is_asked_by_its_ref_once_and_never_cached_while_it_has_none(): void
    {
        $video = new FakeVideoClient('mploynow');
        $rooms = new CallRooms($video, $this->cache(), fn () => null);

        $this->assertNull($rooms->name('call-1'));
        $room = $video->createRoom('call-1', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+2 hours'));
        $this->assertSame($room->name, $rooms->name('call-1'), 'found the moment it is made');
        $this->assertSame($room->address(), $rooms->address('call-1'));
        $this->assertSame('call-1', $rooms->refFor($room->name));

        $asked = $video->callCount('roomForCall');
        $rooms->name('call-1');
        $rooms->address('call-1');
        $this->assertSame($asked, $video->callCount('roomForCall'), 'a room keeps its name for life: cached');
    }

    public function test_more_time_moves_the_rooms_close_and_the_next_read_sees_it(): void
    {
        $video = new FakeVideoClient('mploynow');
        $video->createRoom('call-2', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+2 hours'));
        $rooms = new CallRooms($video, $this->cache(), fn () => null);
        $before = $rooms->closesAt('call-2');

        $until = new \DateTimeImmutable('+3 hours');
        $rooms->extendTo('call-2', $until);

        $this->assertNotEquals($before, $rooms->closesAt('call-2'));
        $this->assertSame($until->getTimestamp(), $rooms->closesAt('call-2')?->getTimestamp());
    }

    public function test_an_outage_reads_as_no_room_and_is_reported(): void
    {
        $video = new FakeVideoClient('mploynow');
        $video->failNext(new VideoServiceUnavailable('down', 'unavailable', 503));
        $reported = 0;
        $rooms = new CallRooms($video, $this->cache(), function () use (&$reported) { $reported++; });

        $this->assertNull($rooms->name('call-3'));
        $this->assertSame(1, $reported);
    }

    public function test_a_list_asks_for_its_rooms_once(): void
    {
        $video = new FakeVideoClient('mploynow');
        $a = $video->createRoom('call-a', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+2 hours'));
        $b = $video->createRoom('call-b', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+2 hours'));
        $rooms = new CallRooms($video, $this->cache(), fn () => null);

        $rooms->preload(['call-a', 'call-b', 'call-none', null]);
        $this->assertSame([$a->name, $b->name, null], [$rooms->name('call-a'), $rooms->name('call-b'), $video->roomForCall('call-none')?->name]);
        $this->assertSame(1, $video->callCount('callSummaries'));
        $this->assertSame(1, $video->callCount('roomForCall'), 'only the test itself asked one by one');
    }
}
