<?php

namespace Shirahcan\VideoClient\Laravel\Events;

use Shirahcan\VideoClient\VideoEvent;
use Shirahcan\VideoClient\WebhookSignature;

/**
 * Receives one callback from video-service, the same in every product (VideoKit::callbackRoute):
 * verifies the signature, drops a delivery it has already handled, and hands the event to the
 * product as VideoEventReceived. Pure, so it is tested without a framework.
 *
 * Status contract with the service: 200 done (or a duplicate), 401 refused (bad or missing
 * signature, never retried into success), 500 the product failed (the service retries, and the
 * event is NOT marked seen, so the retry runs it).
 */
final class CallbackReceiver
{
    /**
     * @param  \Closure(string): bool  $claim    true when this event id was not seen before (marks it)
     * @param  \Closure(string): void  $release  un-marks an id whose handling failed
     * @param  \Closure(object): void  $dispatch the product's event bus
     * @param  \Closure(\Throwable): void  $report
     */
    public function __construct(
        private readonly string $secret,
        private readonly \Closure $claim,
        private readonly \Closure $release,
        private readonly \Closure $dispatch,
        private readonly \Closure $report,
    ) {}

    /** @return array{0: int, 1: string} status code and message */
    public function receive(string $rawBody, ?string $signature, ?string $timestamp): array
    {
        if ($this->secret === '' || ! WebhookSignature::verify($rawBody, $signature, $timestamp, $this->secret)) {
            ($this->report)(new \RuntimeException('video-service callback refused: '.($this->secret === '' ? 'VIDEO_SERVICE_CALLBACK_SECRET is not set' : 'signature mismatch')));

            return [401, 'Invalid signature'];
        }

        $event = VideoEvent::fromArray((array) json_decode($rawBody, true));
        if ($event->event === '') {
            return [200, 'Not an event'];
        }

        if ($event->eventId !== '' && ! ($this->claim)($event->eventId)) {
            return [200, 'Already received'];
        }

        try {
            ($this->dispatch)(new VideoEventReceived($event));
        } catch (\Throwable $e) {
            if ($event->eventId !== '') {
                ($this->release)($event->eventId);
            }
            ($this->report)($e);

            return [500, 'Internal server error'];
        }

        return [200, 'Received'];
    }
}
