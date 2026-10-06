<?php

namespace Shirahcan\VideoClient\Tests;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Shirahcan\VideoClient\CallWindows;
use Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable;
use Shirahcan\VideoClient\FakeVideoClient;
use Shirahcan\VideoClient\VideoPolicy;

class CallWindowsTest extends TestCase
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

    public function test_the_policy_is_asked_once_then_cached(): void
    {
        $client = new FakeVideoClient;
        $client->policyAnswer = VideoPolicy::fromArray(['window' => ['before_minutes' => 20]]);
        $windows = new CallWindows($client, $this->cache());

        $this->assertSame(20, $windows->current()->policy()->opensBeforeMinutes);
        $this->assertSame(20, $windows->current()->policy()->opensBeforeMinutes);
        $this->assertSame(1, $client->callCount('policy'));
    }

    public function test_a_service_that_cannot_answer_falls_back_to_its_last_answer_never_a_constant(): void
    {
        $client = new FakeVideoClient;
        $cache = $this->cache();
        $cache->set(CallWindows::LAST_GOOD_KEY, VideoPolicy::fromArray(['window' => ['before_minutes' => 25]])->toArray());
        $client->failNext(new VideoServiceUnavailable('down', 'service_unavailable', 503));

        $this->assertSame(25, (new CallWindows($client, $cache))->policy()->opensBeforeMinutes);
    }

    public function test_with_no_answer_ever_it_fails_loudly(): void
    {
        $client = new FakeVideoClient;
        $client->failNext(new VideoServiceUnavailable('down', 'service_unavailable', 503));

        $this->expectException(VideoServiceUnavailable::class);
        (new CallWindows($client, $this->cache()))->policy();
    }
}
