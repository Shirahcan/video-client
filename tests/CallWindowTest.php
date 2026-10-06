<?php

namespace Shirahcan\VideoClient\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Shirahcan\VideoClient\CallWindow;
use Shirahcan\VideoClient\VideoPolicy;

class CallWindowTest extends TestCase
{
    private function window(array $policy = []): CallWindow
    {
        return new CallWindow(VideoPolicy::fromArray($policy));
    }

    private function at(string $t): DateTimeImmutable
    {
        return new DateTimeImmutable($t);
    }

    public function test_the_window_is_the_services_numbers(): void
    {
        $w = $this->window(['window' => ['before_minutes' => 20, 'after_minutes' => 10, 'expired_after_close_minutes' => 45]]);
        $start = $this->at('2026-11-02T15:00:00Z');
        $end = $this->at('2026-11-02T15:30:00Z');

        $this->assertEquals($this->at('2026-11-02T14:40:00Z'), $w->opensAt($start));
        $this->assertEquals($this->at('2026-11-02T15:40:00Z'), $w->closesAt($end));
        $this->assertFalse($w->isOpen($start, $end, null, $this->at('2026-11-02T14:39:00Z')));
        $this->assertTrue($w->isOpen($start, $end, null, $this->at('2026-11-02T14:40:00Z')));
        $this->assertFalse($w->isOpen($start, $end, null, $this->at('2026-11-02T15:41:00Z')));
        $this->assertFalse($w->isExpired($end, null, $this->at('2026-11-02T16:25:00Z')));
        $this->assertTrue($w->isExpired($end, null, $this->at('2026-11-02T16:26:00Z')));
    }

    public function test_an_extension_keeps_it_open_but_never_shortens_it(): void
    {
        $w = $this->window();
        $start = $this->at('2026-11-02T15:00:00Z');
        $end = $this->at('2026-11-02T15:30:00Z');

        $this->assertTrue($w->isOpen($start, $end, $this->at('2026-11-02T16:30:00Z'), $this->at('2026-11-02T16:20:00Z')));
        $this->assertEquals($this->at('2026-11-02T16:00:00Z'), $w->closesAt($end, $this->at('2026-11-02T15:45:00Z')));
    }

    public function test_extension_limits_come_from_the_policy(): void
    {
        $w = $this->window(['extension' => ['steps_minutes' => [10, 20], 'max_extra_minutes' => 60]]);

        $this->assertTrue($w->allowsExtensionStep(20));
        $this->assertFalse($w->allowsExtensionStep(30));
        $this->assertEquals($this->at('2026-11-02T17:00:00Z'), $w->latestExtension($this->at('2026-11-02T15:30:00Z')));
    }
}
