<?php

namespace Observe\Laravel\Agent;

use Closure;
use Observe\Laravel\Envelope;
use Observe\Laravel\Ids;
use Observe\Laravel\Transport\Ingest;
use Observe\Laravel\Transport\Result;

final class Agent
{
    public const TICK_SECONDS = 0.2;

    public const HEARTBEAT_SECONDS = 1.0;

    public const BACKOFF_SCAN_SECONDS = 5.0;

    public const SWEEP_SECONDS = 60.0;

    public const FLUSH_SECONDS = 5.0;

    public const REPORT_SECONDS = 60.0;

    private Drops $drops;

    private bool $refusing = false;

    private float $lastScan = -INF;

    private float $nextSweep = 0.0;

    private float $nextHeartbeat = 0.0;

    private float $nextReport = 0.0;

    private readonly Report $report;

    public function __construct(
        private readonly Spool $spool,
        private readonly Backlog $backlog,
        private readonly Health $health,
        private readonly Sender $sender,
        private readonly Envelope $envelope,
        private readonly Closure $clock,
        ?Report $report = null,
    ) {
        $this->drops = new Drops;
        $this->report = $report ?? Report::silent();
    }

    public static function for(AgentSettings $settings, Ingest $ingest, Envelope $envelope, ?Report $report = null): self
    {
        return new self(
            // A zero-downtime release reaches storage through a symlink, and the release this agent started from is deleted a few deploys later.
            new Spool(realpath($settings->path) ?: $settings->path),
            new Backlog($settings->maxBytes, $settings->maxFiles),
            new Health(fn (float $seconds): float => $seconds * (0.8 + 0.4 * mt_rand() / mt_getrandmax())),
            new CurlMultiSender($ingest),
            $envelope,
            fn (): float => microtime(true),
            $report,
        );
    }

    public function run(Closure $stopping): void
    {
        $waiting = false;

        while (! $this->spool->lock()) {
            if (! $waiting) {
                $this->report->say('Another Observe agent is running on this directory. Waiting for it to stop.');
                $waiting = true;
            }

            if ($stopping()) {
                return;
            }

            usleep((int) (self::TICK_SECONDS * 1_000_000));
        }

        try {
            while (! $stopping()) {
                $this->tick();
            }

            $deadline = ($this->clock)() + self::FLUSH_SECONDS;

            while ($this->backlog->inFlight() > 0 && ($this->clock)() < $deadline) {
                $this->heartbeat(($this->clock)());
                $this->apply($this->sender->poll(self::TICK_SECONDS));
            }
        } finally {
            $this->sender->abandon();
            $this->spool->unlock();
        }
    }

    public function tick(): void
    {
        $now = ($this->clock)();

        if ($now >= $this->lastScan + ($this->health->healthy() ? self::TICK_SECONDS : self::BACKOFF_SCAN_SECONDS)) {
            $evictions = $this->backlog->sync($this->spool->list(), Ids::micros($now));
            array_map($this->spool->delete(...), $evictions->all());
            $this->drops->add($evictions);
            $this->lastScan = $now;
        }

        if ($now >= $this->nextSweep) {
            $this->spool->sweep($now);
            $this->nextSweep = $now + self::SWEEP_SECONDS;
        }

        $this->heartbeat($now);
        $this->report->tick($now);

        while ($this->backlog->inFlight() < $this->health->allowance($now) && ($file = $this->backlog->next($now)) !== null) {
            $body = $this->spool->read($file);

            if ($body === null) {
                $this->backlog->forget($file);

                continue;
            }

            $this->backlog->start($file, $now);
            $this->sender->start($file, Envelope::restamp($body, Ids::micros($now)));
        }

        $this->apply($this->sender->poll(self::TICK_SECONDS));
    }

    private function apply(array $completions): void
    {
        $now = ($this->clock)();

        foreach ($completions as $completion) {
            $remove = $completion->result === Result::Accepted || $completion->result === Result::Dropped;
            $startedAt = $this->backlog->finish($completion->file, $remove, $now);

            if ($remove) {
                $this->spool->delete($completion->file);
            }

            if ($completion->result === Result::Dropped) {
                $this->drops->rejected++;
            }

            $this->health->record($completion->result, $startedAt, $now);
            $this->report->completed($completion, $now);

            if ($completion->result === Result::Accepted) {
                $this->report($now);
            }
        }
    }

    private function report(float $now): void
    {
        if (! $this->drops->any() || $now < $this->nextReport) {
            return;
        }

        if ($this->spool->put($this->drops->envelope($this->envelope, $now), $now)) {
            $this->drops = new Drops;
            $this->nextReport = $now + self::REPORT_SECONDS;
        }
    }

    private function heartbeat(float $now): void
    {
        if ($now < $this->nextHeartbeat) {
            return;
        }

        $this->spool->publish($this->admitting() ? $now + Spool::STATUS_TTL_SECONDS : 0.0);
        $this->nextHeartbeat = $now + self::HEARTBEAT_SECONDS;
    }

    private function admitting(): bool
    {
        // The disk absorbs an Observe outage, where direct sends would fail too. A backlog while Observe is healthy means this agent is too slow, so workers send directly.
        if (! $this->health->healthy()) {
            return true;
        }

        $load = $this->backlog->load();
        $this->refusing = $this->refusing ? $load > 0.25 : $load > 0.5;

        return ! $this->refusing;
    }
}
