<?php

namespace Shirahcan\VideoClient;

/**
 * One month of video minutes and cost: the estate's, every product's, and which product
 * asked (from its trust key, so a product never has to spell its own name).
 */
final class VideoUsageReport
{
    public function __construct(public readonly array $raw) {}

    public function month(): string
    {
        return (string) ($this->raw['month'] ?? '');
    }

    public function caller(): string
    {
        return (string) ($this->raw['caller'] ?? '');
    }

    /** @return array{minutes: array<string, float>, cost_usd: float, free_call_minutes?: float, rates_missing?: array} */
    public function estate(): array
    {
        return (array) ($this->raw['estate'] ?? []);
    }

    /** @return array<string, array{minutes: array<string, float>, cost_usd: float}> */
    public function products(): array
    {
        return (array) ($this->raw['products'] ?? []);
    }

    /** This product's own figures (zeros when it had no calls this month). */
    public function mine(): array
    {
        return $this->products()[$this->caller()] ?? ['minutes' => ['call' => 0, 'transcription' => 0, 'recording' => 0], 'cost_usd' => 0.0];
    }
}
