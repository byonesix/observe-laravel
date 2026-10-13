<?php

namespace Observe\Laravel\Agent;

use Closure;
use Observe\Laravel\Transport\Result;

final class Health
{
    public const CONCURRENCY = 8;

    public const BASE_SECONDS = 5;

    public const MAX_SECONDS = 300;

    public const UNAUTHORIZED_SECONDS = 3600;

    private int $failures = 0;

    private float $until = 0.0;

    private float $since = 0.0;

    public function __construct(private readonly Closure $jitter) {}

    public function allowance(float $now): int
    {
        return match (true) {
            $this->failures === 0 => self::CONCURRENCY,
            $now < $this->until => 0,
            default => 1,
        };
    }

    public function healthy(): bool
    {
        return $this->failures === 0;
    }

    public function record(Result $result, float $startedAt, float $now): void
    {
        if ($result === Result::Accepted) {
            $this->failures = 0;
            $this->until = 0.0;
            $this->since = $now;

            return;
        }

        if ($startedAt <= $this->since) {
            return;
        }

        match ($result) {
            Result::Failed => $this->open($now, ($this->jitter)((float) min(self::MAX_SECONDS, self::BASE_SECONDS * 2 ** min(10, $this->failures)))),
            Result::Unauthorized => $this->open($now, self::UNAUTHORIZED_SECONDS),
            Result::Dropped, Result::Skipped, Result::Spooled => null,
        };
    }

    private function open(float $now, float $seconds): void
    {
        $this->failures++;
        $this->until = $now + $seconds;
        $this->since = $now;
    }
}
