<?php

namespace Observe\Laravel\Transport;

interface BreakerStore
{
    public function get(): ?BreakerState;

    public function put(?BreakerState $state): void;

    public function claim(float $seconds): bool;

    public function release(): void;
}
