<?php

namespace Observe\Laravel\Agent;

final readonly class SpoolFile
{
    private function __construct(
        public string $name,
        public int $spooledAtUs,
        public int $bytes,
    ) {}

    public static function fresh(int $nowUs, int $bytes): self
    {
        return new self(sprintf('%016d-%s-%d.json', $nowUs, bin2hex(random_bytes(6)), $bytes), $nowUs, $bytes);
    }

    public static function parse(string $filename): ?self
    {
        return preg_match('/\A(\d{16})-[0-9a-f]{12}-(\d+)\.json\z/', $filename, $m) === 1
            ? new self($filename, (int) $m[1], (int) $m[2])
            : null;
    }
}
