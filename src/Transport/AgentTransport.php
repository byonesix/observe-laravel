<?php

namespace Observe\Laravel\Transport;

use Closure;
use Observe\Laravel\Agent\Spool;

final readonly class AgentTransport implements OutageAware, Transport
{
    private Closure $clock;

    public function __construct(
        private Spool $spool,
        private Breaker $direct,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    public function send(string $body): Result
    {
        $now = ($this->clock)();

        if ($this->spool->accepting($now) && $this->spool->put($body, $now)) {
            return Result::Spooled;
        }

        return $this->direct->send($body);
    }

    public function outageAttributes(): array
    {
        return $this->spool->accepting(($this->clock)()) ? [] : $this->direct->outageAttributes();
    }
}
