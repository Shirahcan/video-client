<?php

namespace Shirahcan\VideoClient\Laravel\Presence;

use Shirahcan\VideoClient\Exceptions\VideoServiceException;
use Shirahcan\VideoClient\VideoClient;

/**
 * One presence signal from a call page: relayed to video-service (a join or a heartbeat is
 * the service's witness; a leave is the product's business only), then handed to the product
 * as CallPresenceReceived. A service that cannot be reached is reported, never fatal: presence
 * must not disturb a call.
 */
class RelayPresence
{
    /** @param callable(object): void $dispatch */
    public function __construct(
        private readonly VideoClient $client,
        private $dispatch,
    ) {}

    public function relay(PresenceParticipant $who, string $kind, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();

        if ($who->room !== null && $kind !== 'leave') {
            try {
                $this->client->presence($who->room, $who->participantId, $at);
            } catch (VideoServiceException $e) {
                if (function_exists('report')) {
                    report($e);
                }
            }
        }

        ($this->dispatch)(new CallPresenceReceived($who, $kind, $at));
    }
}
