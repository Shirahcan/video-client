<?php

namespace Shirahcan\VideoClient;

use Psr\SimpleCache\CacheInterface as Cache;
use Shirahcan\VideoClient\Exceptions\VideoServiceException;

/**
 * Hands out the shared CallWindow, built from the service's policy.
 *
 * The policy is read at most every ten minutes (a product asks "is this joinable" on every list
 * render). If the service cannot answer, the LAST answer it gave is used: those are still the
 * service's numbers, just not the newest. With no answer ever received it fails loudly; a
 * product never falls back to a number of its own.
 */
class CallWindows
{
    public const CACHE_KEY = 'video-client.policy';

    public const LAST_GOOD_KEY = 'video-client.policy.last-good';

    public const TTL_SECONDS = 600;

    public function __construct(
        private readonly VideoClient $client,
        private readonly Cache $cache,
    ) {}

    public function current(): CallWindow
    {
        return new CallWindow($this->policy());
    }

    public function policy(): VideoPolicy
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return VideoPolicy::fromArray($cached);
        }

        try {
            $policy = $this->client->policy();
        } catch (VideoServiceException $e) {
            $last = $this->cache->get(self::LAST_GOOD_KEY);
            if (is_array($last)) {
                if (function_exists('report')) {
                    report($e);
                }

                return VideoPolicy::fromArray($last);
            }

            throw $e;
        }

        $this->cache->set(self::CACHE_KEY, $policy->toArray(), self::TTL_SECONDS);
        $this->cache->set(self::LAST_GOOD_KEY, $policy->toArray());

        return $policy;
    }
}
