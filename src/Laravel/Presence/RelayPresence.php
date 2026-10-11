<?php

namespace Shirahcan\VideoClient\Laravel\Presence;

use Shirahcan\VideoClient\Exceptions\VideoServiceException;
use Shirahcan\VideoClient\VideoClient;

/**
 * One presence signal from a call page: relayed to video-service (the service's witness, and
 * with a call ref its leaves too, so it can say whether a call is live), then handed to the product
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

        try {
            if ($who->callRef !== null) {
                // By the call's ref: a call with no room is witnessed too, and a leave is kept.
                $this->client->callPresence($who->callRef, $who->participantId, $kind, $at);
            } elseif ($who->room !== null && $kind !== 'leave') {
                $this->client->presence($who->room, $who->participantId, $at);
            }
        } catch (VideoServiceException $e) {
            if (function_exists('report')) {
                report($e);
            }
        }

        ($this->dispatch)(new CallPresenceReceived($who, $kind, $at));
    }
}
