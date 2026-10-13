<?php

namespace Observe\Laravel;

final readonly class TraceContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?bool $sampled,
    ) {}

    public static function parse(mixed $header): ?self
    {
        if (! is_string($header)) {
            return null;
        }

        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(?:-.*)?$/', strtolower(trim($header)), $m) !== 1) {
            return null;
        }

        if ($m[1] === 'ff' || $m[2] === str_repeat('0', 32) || $m[3] === str_repeat('0', 16)) {
            return null;
        }

        return new self($m[2], $m[3], (hexdec($m[4]) & 1) === 1);
    }

    public function traceparent(): string
    {
        return '00-'.$this->traceId.'-'.$this->spanId.'-'.($this->sampled ? '01' : '00');
    }
}
