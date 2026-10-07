<?php

namespace Shirahcan\VideoClient\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\Laravel\Events\CallbackReceiver;
use Shirahcan\VideoClient\Laravel\Events\VideoEventReceived;

class CallbackReceiverTest extends TestCase
{
    private array $seen = [];
    private array $dispatched = [];
    private array $reported = [];
    private ?\Throwable $failWith = null;

    private function receiver(string $secret): CallbackReceiver
    {
        return new CallbackReceiver(
            $secret,
            function (string $id) {
                if (isset($this->seen[$id])) {
                    return false;
                }
                $this->seen[$id] = true;

                return true;
            },
            function (string $id) {
                unset($this->seen[$id]);
            },
            function (object $e) {
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }
                $this->dispatched[] = $e;
            },
            function (\Throwable $e) {
                $this->reported[] = $e;
            },
        );
    }

    private function signed(string $body, string $secret): array
    {
        $ts = (string) time();

        return [$body, base64_encode(hash_hmac('sha256', "{$ts}.{$body}", base64_decode($secret), true)), $ts];
    }

    private function body(string $eventId = 'e1'): string
    {
        return (string) json_encode(['event' => 'participant.joined', 'event_id' => $eventId, 'room' => 'r', 'external_ref' => 'm-1', 'payload' => ['user_id' => 'u-1']]);
    }

    public function test_a_signed_event_reaches_the_product_once(): void
    {
        $secret = base64_encode('s3cret');
        $r = $this->receiver($secret);

        $this->assertSame([200, 'Received'], $r->receive(...$this->signed($this->body(), $secret)));
        $this->assertSame([200, 'Already received'], $r->receive(...$this->signed($this->body(), $secret)));

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(VideoEventReceived::class, $this->dispatched[0]);
        $this->assertSame('m-1', $this->dispatched[0]->event->externalRef);
    }

    public function test_a_forged_or_unconfigured_callback_is_refused_and_reported(): void
    {
        $secret = base64_encode('s3cret');
        [$body, , $ts] = $this->signed($this->body(), $secret);

        $this->assertSame(401, $this->receiver($secret)->receive($body, 'forged', $ts)[0]);
        $this->assertSame(401, $this->receiver('')->receive(...$this->signed($this->body(), $secret))[0]);
        $this->assertSame([], $this->dispatched);
        $this->assertCount(2, $this->reported);
    }

    public function test_a_failure_is_retried_rather_than_remembered(): void
    {
        $secret = base64_encode('s3cret');
        $r = $this->receiver($secret);

        $this->failWith = new \RuntimeException('db down');
        $this->assertSame(500, $r->receive(...$this->signed($this->body(), $secret))[0]);

        $this->failWith = null;
        $this->assertSame([200, 'Received'], $r->receive(...$this->signed($this->body(), $secret)));
        $this->assertCount(1, $this->dispatched);
    }
}
