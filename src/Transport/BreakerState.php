<?php

namespace Observe\Laravel\Transport;

final readonly class BreakerState
{
    public function __construct(
        public float $openUntil,
        public int $failures,
        public float $openedAt,
    ) {}

    public static function decode(mixed $raw): ?self
    {
        if (! is_string($raw) || preg_match('/^(\d+(?:\.\d+)?) (\d+) (\d+(?:\.\d+)?)$/', trim($raw), $m) !== 1) {
            return null;
        }

        return new self((float) $m[1], (int) $m[2], (float) $m[3]);
    }

    public function encode(): string
    {
        return sprintf('%.3F %d %.3F', $this->openUntil, $this->failures, $this->openedAt);
    }
}
