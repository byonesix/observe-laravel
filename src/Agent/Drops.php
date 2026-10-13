<?php

namespace Observe\Laravel\Agent;

use Observe\Laravel\Envelope;
use Observe\Laravel\Ids;

final class Drops
{
    public int $evicted = 0;

    public int $expired = 0;

    public int $rejected = 0;

    public function add(Evictions $evictions): void
    {
        $this->evicted += count($evictions->evicted);
        $this->expired += count($evictions->expired);
    }

    public function any(): bool
    {
        return $this->evicted + $this->expired + $this->rejected > 0;
    }

    public function envelope(Envelope $envelope, float $now): string
    {
        return $envelope->encode($envelope->prepare([], [], [[
            'time_us' => Ids::micros($now),
            'level' => 'warning',
            'message' => 'Observe agent dropped envelopes',
            'trace_id' => null,
            'span_id' => null,
            'error_id' => null,
            'attributes' => [
                'observe.dropped.evicted' => $this->evicted,
                'observe.dropped.expired' => $this->expired,
                'observe.dropped.rejected' => $this->rejected,
            ],
            'context' => [],
        ]]));
    }
}
