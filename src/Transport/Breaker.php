<?php

namespace Observe\Laravel\Transport;

use Closure;
use Throwable;

final readonly class Breaker implements OutageAware, Transport
{
    public const BASE_SECONDS = 30;

    public const MAX_SECONDS = 300;

    public const UNAUTHORIZED_SECONDS = 3600;

    public const MIN_PROBE_SECONDS = 2.0;

    private Closure $clock;

    public function __construct(
        private Transport $inner,
        private BreakerStore $store,
        ?Closure $clock = null,
        private float $probeSeconds = self::MIN_PROBE_SECONDS,
    ) {
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    public function send(string $body): Result
    {
        try {
            return $this->attempt($body);
        } catch (Throwable) {
            return Result::Failed;
        }
    }

    public function outageAttributes(): array
    {
        try {
            $state = $this->store->get();

            return $state === null || ($this->clock)() < $state->openUntil
                ? []
                : ['observe.breaker_opened_at' => gmdate('Y-m-d\\TH:i:s\\Z', (int) $state->openedAt)];
        } catch (Throwable) {
            return [];
        }
    }

    private function attempt(string $body): Result
    {
        $state = $this->store->get();
        $now = ($this->clock)();

        if ($state !== null && $now < $state->openUntil) {
            return Result::Skipped;
        }

        if ($state !== null) {
            if (! $this->store->claim($this->probeSeconds)) {
                return Result::Skipped;
            }

            $this->store->put(new BreakerState($now + $this->probeSeconds, $state->failures, $state->openedAt));
        }

        try {
            $result = $this->inner->send($body);
        } finally {
            if ($state !== null) {
                $this->store->release();
            }
        }
        $failures = ($state->failures ?? 0) + 1;
        $openedAt = $state->openedAt ?? $now;

        match ($result) {
            Result::Accepted, Result::Dropped => $state === null ? null : $this->store->put(null),
            Result::Failed => $this->store->put(new BreakerState(
                $now + min(self::MAX_SECONDS, self::BASE_SECONDS * 2 ** min(10, $failures - 1)),
                $failures,
                $openedAt,
            )),
            Result::Unauthorized => $this->store->put(new BreakerState($now + self::UNAUTHORIZED_SECONDS, $failures, $openedAt)),
            Result::Skipped, Result::Spooled => null,
        };

        return $result;
    }
}
