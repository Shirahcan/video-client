<?php

namespace Shirahcan\VideoClient\Laravel\Events;

use Shirahcan\VideoClient\VideoEvent;

/**
 * One of THIS product's call events, verified and routed by video-service (participant.joined /
 * participant.left, transcript.ready-to-download, meeting.ended ...). The product listens for it
 * and does its own follow-ups; `externalRef` is the product's own id for the call.
 */
final class VideoEventReceived
{
    public function __construct(public readonly VideoEvent $event) {}
}
