<?php

namespace Observe\Laravel\Agent;

use Closure;
use Observe\Laravel\Transport\Result;

final class Report
{
    public const SUMMARY_SECONDS = 60.0;

    private ?string $outcome = null;

    private array $counts = ['sent' => 0, 'rejected' => 0, 'failed' => 0];

    private ?float $nextSummary = null;

    public function __construct(private readonly Closure $say) {}

    public static function silent(): self
    {
        return new self(fn (string $line) => null);
    }

    public function say(string $line): void
    {
        ($this->say)($line);
    }

    public function completed(Completion $completion, float $now): void
    {
        [$outcome, $count, $line] = match ($completion->result) {
            Result::Accepted => ['sent', 'sent', sprintf('Ingest successful [%.3fs]', $completion->seconds)],
            Result::Dropped => ['rejected '.$completion->detail, 'rejected', "Observe rejected an envelope ({$completion->detail}). It was deleted."],
            Result::Unauthorized => ['unauthorized', 'failed', "Observe rejected the key ({$completion->detail}). Check OBSERVE_KEY. Trying again in an hour."],
            Result::Failed => ['failed '.$completion->detail, 'failed', "Ingest failed ({$completion->detail}). Trying again with backoff."],
            Result::Skipped, Result::Spooled => [null, null, null],
        };

        if ($count === null) {
            return;
        }

        $this->counts[$count]++;

        if ($outcome !== $this->outcome) {
            $this->outcome = $outcome;
            $this->say($line);
        }

        $this->tick($now);
    }

    public function tick(float $now): void
    {
        $this->nextSummary ??= $now + self::SUMMARY_SECONDS;

        if ($now < $this->nextSummary) {
            return;
        }

        $this->nextSummary = $now + self::SUMMARY_SECONDS;

        if (array_sum($this->counts) > 0) {
            $this->say(sprintf('Last minute: %d sent, %d rejected, %d failed', ...array_values($this->counts)));
            $this->counts = array_fill_keys(array_keys($this->counts), 0);
        }
    }
}
