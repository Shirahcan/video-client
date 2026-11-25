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

    /**
     * With a `$deviceId` (call continuity) the answer says whether this person is in the call on another
     * device and whether another device took the call from this one. A `check` records nothing and tells
     * the product nothing: the page is only asking before it joins.
     *
     * @return array{elsewhere: ?array{since: string}, superseded: bool}
     */
    public function relay(PresenceParticipant $who, string $kind, ?\DateTimeImmutable $at = null, ?string $deviceId = null, bool $takeOver = false): array
    {
        $at ??= new \DateTimeImmutable();
        $answer = ['elsewhere' => null, 'superseded' => false];
        $deviceId = $deviceId !== null && trim($deviceId) !== '' ? trim($deviceId) : null;

        try {
            if ($who->callRef !== null && $deviceId !== null) {
                $answer = $this->client->callPresenceFromDevice($who->callRef, $who->participantId, $kind, $deviceId, $takeOver, $at);
            } elseif ($kind === 'check') {
                // Nothing to ask without both a device and a call ref.
            } elseif ($who->callRef !== null) {
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

        if ($kind !== 'check') {
            ($this->dispatch)(new CallPresenceReceived($who, $kind, $at));
        }

        return $answer;
    }
}
