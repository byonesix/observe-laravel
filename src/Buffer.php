<?php

namespace Observe\Laravel;

use Throwable;
use WeakMap;

final class Buffer
{
    public const MAX_SPANS = 2000;

    public const MAX_LOGS = 1000;

    public const MAX_ERRORS = 25;

    public const MAX_BYTES = 2_097_152;

    public const MAX_ERROR_BYTES = 1_048_576;

    public array $spans = [];

    public array $logs = [];

    public array $errors = [];

    public array $dropped = ['spans' => 0, 'logs' => 0, 'errors' => 0];

    public ?float $firstAt = null;

    public int $bytes = 0;

    public int $errorBytes = 0;

    private bool $full = false;

    private WeakMap $errorIds;

    public function __construct()
    {
        $this->errorIds = new WeakMap;
    }

    public function roomForLog(): bool
    {
        if (! $this->full && count($this->logs) < self::MAX_LOGS) {
            return true;
        }

        $this->dropped['logs']++;

        return false;
    }

    public function addSpan(array $span, int $bytes): void
    {
        if (count($this->spans) >= self::MAX_SPANS || $this->bytes + $bytes > self::MAX_BYTES) {
            $this->dropped['spans']++;

            return;
        }

        $this->spans[] = $span;
        $this->bytes += $bytes;
    }

    public function addLog(array $log, int $bytes): void
    {
        if (count($this->logs) >= self::MAX_LOGS || $this->bytes + $bytes > self::MAX_BYTES) {
            $this->dropped['logs']++;
            $this->full = true;

            return;
        }

        $this->logs[] = $log;
        $this->bytes += $bytes;
        $this->firstAt ??= microtime(true);
    }

    public function addError(Throwable $throwable, array $error): bool
    {
        $bytes = strlen(Values::json($error));

        if (count($this->errors) >= self::MAX_ERRORS || ($this->errors !== [] && $this->errorBytes + $bytes > self::MAX_ERROR_BYTES)) {
            $this->dropped['errors']++;

            return false;
        }

        $this->errors[] = $error;
        $this->errorBytes += $bytes;
        $this->firstAt ??= microtime(true);
        $this->errorIds[$throwable] = $error['id'];

        return true;
    }

    public function errorIdFor(Throwable $throwable): ?string
    {
        return $this->errorIds[$throwable] ?? null;
    }

    public function shed(): void
    {
        $this->dropped['spans'] += count($this->spans);
        $this->dropped['logs'] += count($this->logs);
        $this->spans = [];
        $this->logs = [];
        $this->bytes = 0;
        $this->full = false;
    }

    public function size(): int
    {
        return count($this->logs) + count($this->errors) + count($this->spans);
    }

    public function isEmpty(): bool
    {
        return $this->logs === [] && $this->errors === [] && $this->spans === [] && array_sum($this->dropped) === 0;
    }
}
