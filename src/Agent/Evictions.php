<?php

namespace Observe\Laravel\Agent;

final readonly class Evictions
{
    public function __construct(
        public array $evicted,
        public array $expired,
    ) {}

    public function all(): array
    {
        return [...$this->evicted, ...$this->expired];
    }
}
