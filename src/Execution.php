<?php

namespace Observe\Laravel;

final class Execution
{
    public const MAX_PENDING = 256;

    public readonly Buffer $buffer;

    public array $entryAttributes = [];

    public array $sharedAttributes = [];

    public array $context = [];

    public ?string $sessionId = null;

    public bool $failed = false;

    public ?float $endedAt = null;

    public ?float $respondedAt = null;

    private array $open = [];

    private array $pending = [];

    public function __construct(
        public readonly Kind $kind,
        public string $name,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly ?bool $upstreamSampled,
        public readonly float $startedAt,
        public readonly int $key,
    ) {
        $this->buffer = new Buffer;
    }

    public static function begin(Kind $kind, string $name, ?TraceContext $parent, float $startedAt, int $key): self
    {
        return new self($kind, $name, $parent->traceId ?? Ids::traceId(), Ids::spanId(), $parent?->spanId, $parent?->sampled, $startedAt, $key);
    }

    public function currentSpanId(): string
    {
        return $this->open === [] ? $this->spanId : $this->open[array_key_last($this->open)]['span_id'];
    }

    public function trace(?string $spanId = null): TraceContext
    {
        return new TraceContext($this->traceId, $spanId ?? $this->currentSpanId(), $this->upstreamSampled);
    }

    public function open(string $kind, string $name, int $key, array $attributes, float $now, ?string $parentSpanId = null): string
    {
        $spanId = Ids::spanId();
        $this->open[] = [
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId ?? $this->currentSpanId(),
            'kind' => $kind,
            'name' => $name,
            'start' => $now,
            'attributes' => $attributes,
            'key' => $key,
        ];

        return $spanId;
    }

    public function pend(string $key, float $now, ?string $spanId = null, array $attributes = []): string
    {
        $spanId ??= Ids::spanId();

        if (! isset($this->pending[$key]) && count($this->pending) >= self::MAX_PENDING) {
            unset($this->pending[array_key_first($this->pending)]);
        }

        $this->pending[$key] = ['span_id' => $spanId, 'parent_span_id' => $this->currentSpanId(), 'start' => $now, 'attributes' => $attributes];

        return $spanId;
    }

    public function restart(string $key, float $now): void
    {
        if (isset($this->pending[$key])) {
            $this->pending[$key]['start'] = $now;
        }
    }

    public function pull(string $key): ?array
    {
        $pending = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        return $pending;
    }

    public function isOpen(int $key): bool
    {
        foreach ($this->open as $scope) {
            if ($scope['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    public function close(int $key, float $now, bool $failed): bool
    {
        if (! $this->isOpen($key)) {
            return false;
        }

        do {
            $scope = array_pop($this->open);
            $this->span(
                $scope['kind'],
                $scope['name'],
                $scope['start'],
                $now,
                $failed && $scope['key'] === $key ? 'error' : 'ok',
                $scope['attributes'],
                $scope['span_id'],
                $scope['parent_span_id'],
            );
        } while ($scope['key'] !== $key);

        return true;
    }

    public function closeAll(float $now): void
    {
        while ($this->open !== []) {
            $this->close($this->open[array_key_last($this->open)]['key'], $now, false);
        }
    }

    public function span(string $kind, string $name, float $start, float $end, string $status, array $attributes, ?string $spanId = null, ?string $parentSpanId = null): void
    {
        $name = Values::truncate($name, Values::MAX_NAME_BYTES * Values::CAPTURE);
        $attributes = array_map(fn (mixed $v) => is_string($v) ? Values::truncate($v, Values::MAX_ATTRIBUTE_BYTES * Values::CAPTURE) : $v, $attributes);

        $this->buffer->addSpan([
            'trace_id' => $this->traceId,
            'span_id' => $spanId ?? Ids::spanId(),
            'parent_span_id' => $parentSpanId ?? $this->currentSpanId(),
            'kind' => $kind,
            'name' => $name,
            'start_us' => Ids::micros($start),
            'duration_us' => max(0, Ids::micros($end) - Ids::micros($start)),
            'status' => $status,
            'attributes' => $attributes,
        ], 256 + strlen($name) + array_sum(array_map(fn (mixed $v) => is_string($v) ? strlen($v) : 16, $attributes)));
    }

    public function end(float $now, bool $failed): void
    {
        $this->endedAt ??= $now;
        $this->failed = $this->failed || $failed;
    }

    public function ended(): bool
    {
        return $this->endedAt !== null;
    }

    public function errored(): bool
    {
        return $this->failed || $this->buffer->errors !== [];
    }

    public function durationUs(): int
    {
        return max(0, Ids::micros($this->endedAt ?? microtime(true)) - Ids::micros($this->startedAt));
    }
}
