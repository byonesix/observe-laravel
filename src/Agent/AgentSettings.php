<?php

namespace Observe\Laravel\Agent;

final readonly class AgentSettings
{
    public const DEFAULT_MAX_MB = 256;

    public const DEFAULT_MAX_FILES = 25_000;

    public function __construct(
        public string $path,
        public int $maxBytes,
        public int $maxFiles,
    ) {}
}
