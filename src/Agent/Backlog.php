<?php

namespace Observe\Laravel\Agent;

final class Backlog
{
    public const MAX_AGE_US = 6 * 86_400 * 1_000_000;

    private array $files = [];

    private array $inFlight = [];

    private array $attempts = [];

    private array $retryAt = [];

    private int $bytes = 0;

    public function __construct(
        private readonly int $maxBytes,
        private readonly int $maxFiles,
    ) {}

    public function sync(array $onDisk, int $nowUs): Evictions
    {
        $files = [];

        foreach ($onDisk as $file) {
            $files[$file->name] = $file;
        }

        $missing = array_diff_key(array_intersect_key($this->files, $this->inFlight), $files);

        if ($missing !== []) {
            $files += $missing;
            ksort($files, SORT_STRING);
        }

        $bytes = array_sum(array_map(fn (SpoolFile $file) => $file->bytes, $files));
        $count = count($files);
        $evicted = [];
        $expired = [];

        foreach ($files as $name => $file) {
            $old = $file->spooledAtUs < $nowUs - self::MAX_AGE_US;

            if (! $old && $count <= $this->maxFiles && $bytes <= $this->maxBytes) {
                break;
            }

            if (isset($this->inFlight[$name])) {
                continue;
            }

            if ($old) {
                $expired[] = $file;
            } else {
                $evicted[] = $file;
            }

            unset($files[$name]);
            $count--;
            $bytes -= $file->bytes;
        }

        $this->files = $files;
        $this->bytes = $bytes;
        $this->attempts = array_intersect_key($this->attempts, $files);
        $this->retryAt = array_intersect_key($this->retryAt, $files);

        return new Evictions($evicted, $expired);
    }

    public function next(float $now): ?SpoolFile
    {
        foreach ($this->files as $name => $file) {
            if (! isset($this->inFlight[$name]) && ($this->retryAt[$name] ?? 0.0) <= $now) {
                return $file;
            }
        }

        return null;
    }

    public function start(SpoolFile $file, float $now): void
    {
        $this->inFlight[$file->name] = $now;
    }

    public function finish(SpoolFile $file, bool $remove, float $now): float
    {
        $startedAt = $this->inFlight[$file->name] ?? 0.0;
        unset($this->inFlight[$file->name]);

        if ($remove) {
            $this->forget($file);

            return $startedAt;
        }

        $attempts = $this->attempts[$file->name] = ($this->attempts[$file->name] ?? 0) + 1;
        // Starting at twice the first Health backoff lets the next probe pick another file, so one envelope that always fails cannot hold up the rest.
        $this->retryAt[$file->name] = $now + min(Health::MAX_SECONDS, Health::BASE_SECONDS * 2 ** min(10, $attempts));

        return $startedAt;
    }

    public function forget(SpoolFile $file): void
    {
        if (isset($this->files[$file->name])) {
            $this->bytes -= $this->files[$file->name]->bytes;
            unset($this->files[$file->name], $this->attempts[$file->name], $this->retryAt[$file->name]);
        }
    }

    public function inFlight(): int
    {
        return count($this->inFlight);
    }

    public function load(): float
    {
        return max($this->bytes / $this->maxBytes, count($this->files) / $this->maxFiles);
    }
}
