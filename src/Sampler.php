<?php

namespace Observe\Laravel;

final readonly class Sampler
{
    public function __construct(
        private float $rate,
        private array $slowMs,
    ) {}

    public function keep(Kind $kind, string $traceId, ?bool $upstreamSampled, bool $errored, int $durationUs): bool
    {
        return $upstreamSampled === true
            || $errored
            || $durationUs >= ($this->slowMs[$kind->value] ?? PHP_INT_MAX) * 1000
            || self::point($traceId) < $this->rate;
    }

    public static function point(string $traceId): float
    {
        return hexdec(substr($traceId, -13)) / 0x10000000000000;
    }
}
