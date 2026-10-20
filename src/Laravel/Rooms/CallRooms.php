<?php

namespace Shirahcan\VideoClient\Laravel\Rooms;

use Psr\SimpleCache\CacheInterface as Cache;
use Shirahcan\VideoClient\VideoClient;
use Shirahcan\VideoClient\VideoRoom;

/**
 * A call's video room, as video-service knows it: asked for by the call's ref (the product's own id
 * for the call), never kept on the product's record. Every room is on the service's registry.
 *
 * A room keeps its name and address for life (a repair rebuilds it under the same name), so they are
 * cached; a call with no room yet is never cached, so the room is found the moment it is made. The
 * room's close (its `exp`, which "more time" moves) is cached briefly and forgotten on a move.
 *
 * Never throws: a box with no service configured, or an outage, reads as "no room known".
 */
class CallRooms
{
    private const TTL_SECONDS = 86400;

    private const CLOSE_TTL_SECONDS = 300;

    /**
     * @param VideoClient|\Closure(): VideoClient $client resolved on first use, inside the error handling
     * @param callable(\Throwable): void $report
     */
    public function __construct(
        private VideoClient|\Closure $client,
        private Cache $cache,
        private $report,
    ) {}

    /** The room's name for this call, or null when it has none (or the service cannot say). */
    public function name(?string $ref): ?string
    {
        return $this->remembered($ref, 'call_room:', fn (VideoRoom $room) => $room->name);
    }

    /** The room's address (joined with a token; never handed to a browser bare). */
    public function address(?string $ref): ?string
    {
        return $this->remembered($ref, 'call_room_addr:', fn (VideoRoom $room) => $room->address());
    }

    /**
     * A whole list's rooms in one ask (the service's call summaries name each call's room), so a
     * calendar of a hundred calls costs one call to the service, not a hundred. Refs already
     * known are skipped; a call with no room stays unknown (asked again when it gets one).
     *
     * @param iterable<string|null> $refs
     */
    public function preload(iterable $refs): void
    {
        $ask = [];
        foreach ($refs as $ref) {
            if (is_string($ref) && $ref !== '' && ! is_string($this->cache->get('call_room:'.$ref))) {
                $ask[$ref] = true;
            }
        }
        foreach (array_chunk(array_keys($ask), 200) as $chunk) {
            try {
                foreach ($this->client()->callSummaries($chunk) as $ref => $summary) {
                    if (is_string($summary->room) && $summary->room !== '') {
                        $this->cache->set('call_room:'.$ref, $summary->room, self::TTL_SECONDS);
                        $this->cache->set('room_call:'.$summary->room, (string) $ref, self::TTL_SECONDS);
                    }
                }
            } catch (\Throwable $e) {
                ($this->report)($e);
            }
        }
    }

    public function exists(?string $ref): bool
    {
        return $this->name($ref) !== null;
    }

    /** The call a room belongs to (a webhook names only the room). */
    public function refFor(?string $roomName): ?string
    {
        $roomName = trim((string) $roomName);
        if ($roomName === '') {
            return null;
        }
        $ref = $this->cache->get('room_call:'.$roomName);
        if (is_string($ref) && $ref !== '') {
            return $ref;
        }
        try {
            $ref = $this->client()->room($roomName)->externalRef;
        } catch (\Throwable) {
            return null;   // not this product's room, or the service is unreachable
        }
        $this->cache->set('room_call:'.$roomName, $ref, self::TTL_SECONDS);

        return $ref;
    }

    /** A room was just made or rebuilt for this call: remember its name and address now. */
    public function remember(string $ref, string $roomName, ?string $address = null): void
    {
        $this->cache->set('call_room:'.$ref, $roomName, self::TTL_SECONDS);
        $this->cache->set('room_call:'.$roomName, $ref, self::TTL_SECONDS);
        if ($address !== null && $address !== '') {
            $this->cache->set('call_room_addr:'.$ref, $address, self::TTL_SECONDS);
        }
    }

    /**
     * Until when the room is kept open (video-service's own close, which "more time" moves). Ask it
     * only around the call's end: it is one call to the service per call, cached for five minutes.
     */
    public function closesAt(?string $ref): ?\DateTimeImmutable
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        $key = 'call_room_close:'.$ref;
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached === '' ? null : new \DateTimeImmutable($cached);
        }
        try {
            $exp = $this->client()->roomForCall($ref)?->expiresAt;
        } catch (\Throwable $e) {
            ($this->report)($e);

            return null;
        }
        $this->cache->set($key, (string) $exp, self::CLOSE_TTL_SECONDS);

        return $exp ? new \DateTimeImmutable($exp) : null;
    }

    /** The room's close just moved (an extension): the next read asks the service. */
    public function forgetClose(?string $ref): void
    {
        if ($ref !== null && $ref !== '') {
            $this->cache->delete('call_room_close:'.$ref);
        }
    }

    /**
     * Keep the room open until $until (the product has applied the service's steps and cap), and
     * forget the cached close. Throws the service's exception: the caller says why to the person.
     */
    public function extendTo(string $ref, \DateTimeInterface $until): VideoRoom
    {
        $name = $this->name($ref) ?? throw new \RuntimeException('This call has no video room to keep open.');
        $room = $this->client()->setExpiry($name, $until);
        $this->forgetClose($ref);

        return $room;
    }

    /** @param \Closure(VideoRoom): string $pick */
    private function remembered(?string $ref, string $prefix, \Closure $pick): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        $cached = $this->cache->get($prefix.$ref);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        try {
            $room = $this->client()->roomForCall($ref);
        } catch (\Throwable $e) {
            ($this->report)($e);

            return null;
        }
        if ($room === null) {
            return null;
        }
        $this->remember($ref, $room->name, $room->address());

        return $pick($room);
    }

    private function client(): VideoClient
    {
        if ($this->client instanceof \Closure) {
            $this->client = ($this->client)();
        }

        return $this->client;
    }
}
